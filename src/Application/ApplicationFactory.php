<?php

declare(strict_types=1);

namespace Tms\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Throwable;
use Tms\Domain\Attachment\AttachmentPolicy;
use Tms\Domain\Attachment\AttachmentRepository;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\CustomField\CustomFieldRepository;
use Tms\Domain\CustomField\CustomFieldValueCodec;
use Tms\Domain\CustomField\TaskCustomFieldValueRepository;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Notification\SmtpSettingsRepository;
use Tms\Domain\Notification\TelegramLinkTokenRepository;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\Task\TaskRepository;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\Domain\User\UserRepository;
use Tms\Http\Controller\AttachmentController;
use Tms\Http\Controller\AuthController;
use Tms\Http\Controller\CalendarController;
use Tms\Http\Controller\CustomerSearchController;
use Tms\Http\Controller\CustomFieldController;
use Tms\Http\Controller\DashboardController;
use Tms\Http\Controller\LocaleController;
use Tms\Http\Controller\MetadataController;
use Tms\Http\Controller\NotificationAdminController;
use Tms\Http\Controller\NotificationSettingsController;
use Tms\Http\Controller\QuickTaskController;
use Tms\Http\Controller\StatusDefaultsController;
use Tms\Http\Controller\TaskController;
use Tms\Http\Controller\TaskDeleteController;
use Tms\Http\Controller\TaskStatusController;
use Tms\Http\Controller\TelegramWebhookController;
use Tms\Http\CookiePolicy;
use Tms\Http\Middleware\CsrfMiddleware;
use Tms\Http\Middleware\PersistentLoginMiddleware;
use Tms\Http\Middleware\RequireAdminMiddleware;
use Tms\Http\Middleware\RequireAuthMiddleware;
use Tms\Http\Middleware\SanitizeTaskDescriptionMiddleware;
use Tms\I18n\Translator;
use Tms\Infrastructure\AttachmentStorage;
use Tms\Infrastructure\Database;
use Tms\Infrastructure\NativeSessionIdRegenerator;
use Tms\Infrastructure\SecretBox;
use Tms\Infrastructure\SmtpEmailSender;
use Tms\Infrastructure\TelegramBotSender;
use Tms\Security\PasswordAuthenticator;
use Tms\Security\PersistentLoginService;
use Tms\Security\RememberTokenRepository;
use Tms\Security\SessionManager;
use Tms\Security\TaskDescriptionSanitizer;
use Twig\TwigFunction;

final class ApplicationFactory
{
    public function run(): void
    {
        $appTimezone = $this->env('APP_TIMEZONE', 'UTC');
        $timezoneOffset = $this->configureTimezone($appTimezone);
        $appUrl = $this->requiredEnv('APP_URL');
        $debug = $this->boolEnv('APP_DEBUG', false);
        $sameSite = $this->env('SESSION_SAMESITE', 'Lax');
        $secureCookies = $this->boolEnv('SESSION_SECURE', true);
        $rememberCookieName = $this->env('REMEMBER_COOKIE_NAME', 'tms_remember');
        $rememberDays = max(1, (int) $this->env('REMEMBER_DAYS', '30'));
        $rememberLifetime = new DateInterval('P' . $rememberDays . 'D');
        $attachmentMaxBytes = $this->positiveIntEnv('ATTACHMENT_MAX_BYTES', 10_485_760);
        $attachmentStoragePath = $this->env('ATTACHMENT_STORAGE_PATH', dirname(__DIR__, 2) . '/var/storage/attachments');
        $notificationSecretRaw = $this->env('NOTIFICATION_SECRET', '');
        $notificationSecret = $notificationSecretRaw !== '' ? new SecretBox($notificationSecretRaw) : null;
        $telegramBotToken = $this->env('TELEGRAM_BOT_TOKEN', '');
        $telegramBotName = $this->env('TELEGRAM_BOT_NAME', '');
        $telegramWebhookSecret = $this->env('TELEGRAM_WEBHOOK_SECRET', '');

        $this->startSession($secureCookies, $sameSite);

        $translator = new Translator(dirname(__DIR__, 2) . '/resources/i18n', $this->env('APP_LOCALE', 'en'));

        $db = (new Database([
            'host' => $this->requiredEnv('DB_HOST'),
            'port' => $this->env('DB_PORT', '3306'),
            'name' => $this->requiredEnv('DB_NAME'),
            'user' => $this->requiredEnv('DB_USER'),
            'password' => $this->requiredEnv('DB_PASS'),
        ]))->connect();
        $this->configureDatabaseTimezone($db, $timezoneOffset);

        $app = SlimAppFactory::create();
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates', ['cache' => false, 'autoescape' => 'html']);

        $descriptionSanitizer = new TaskDescriptionSanitizer();
        $customValueCodec = new CustomFieldValueCodec();
        $twig->getEnvironment()->addFunction(new TwigFunction('t', [$translator, 'trans']));
        $twig->getEnvironment()->addFunction(new TwigFunction('sanitize_task_html', [$descriptionSanitizer, 'sanitize']));
        $twig->getEnvironment()->addFunction(new TwigFunction('custom_field_display', [$customValueCodec, 'display']));
        $twig->getEnvironment()->addGlobal('locale', $translator->locale());

        $users = new UserRepository($db);
        $statuses = new StatusRepository($db);
        $taskTypes = new TaskTypeRepository($db);
        $customers = new CustomerRepository($db);
        $customFields = new CustomFieldRepository($db);
        $customValues = new TaskCustomFieldValueRepository($db);
        $tasks = new TaskRepository($db);
        $attachments = new AttachmentRepository($db);
        $notificationSettings = new NotificationSettingsRepository($db);
        $smtpSettings = new SmtpSettingsRepository($db);
        $telegramLinkTokens = new TelegramLinkTokenRepository($db);
        $attachmentPolicy = new AttachmentPolicy($attachmentMaxBytes);
        $attachmentStorage = new AttachmentStorage($attachmentStoragePath, dirname(__DIR__, 2) . '/public');
        $sessions = new SessionManager(new NativeSessionIdRegenerator());
        $passwordAuthenticator = new PasswordAuthenticator($users);
        $rememberTokens = new RememberTokenRepository($db);
        $persistentLogin = new PersistentLoginService($rememberTokens, $rememberLifetime);
        $cookiePolicy = new CookiePolicy($secureCookies, $sameSite);
        $userBootstrap = new UserBootstrapService($statuses, $taskTypes, $translator);
        $telegramSender = new TelegramBotSender(new Client(), $telegramBotToken);
        $emailSender = new SmtpEmailSender($smtpSettings, $notificationSecret);

        $twig->getEnvironment()->addFunction(new TwigFunction(
            'task_attachments',
            static function (int $taskId) use ($sessions, $attachments): array {
                $userId = $sessions->currentUserId();
                return $userId === null ? [] : $attachments->listForTask($userId, $taskId);
            },
        ));
        $twig->getEnvironment()->addGlobal('attachment_allowed_extensions', $attachmentPolicy->allowedExtensions());
        $twig->getEnvironment()->addGlobal(
            'attachment_max_mib',
            rtrim(rtrim(number_format($attachmentPolicy->maxBytes() / 1_048_576, 1, '.', ''), '0'), '.'),
        );

        $authController = new AuthController($twig, $passwordAuthenticator, $sessions, $persistentLogin, $cookiePolicy, $rememberLifetime, $rememberCookieName, $translator);
        $dashboardController = new DashboardController($twig, $sessions, $tasks, $statuses, $translator);
        $taskController = new TaskController($twig, $sessions, $tasks, $statuses, $taskTypes, $customers, $customFields, $customValues, $customValueCodec, $translator);
        $taskDeleteController = new TaskDeleteController($sessions, $tasks, $attachments, $attachmentStorage);
        $attachmentController = new AttachmentController($sessions, $tasks, $attachments, $attachmentPolicy, $attachmentStorage, $translator);
        $quickTaskController = new QuickTaskController($sessions, $tasks, $statuses, $descriptionSanitizer, $translator);
        $customerSearchController = new CustomerSearchController($sessions, $customers);
        $taskStatusController = new TaskStatusController($sessions, $tasks, $statuses, $translator);
        $calendarController = new CalendarController($twig, $sessions, $tasks, $statuses, $taskTypes, $customers, $translator);
        $localeController = new LocaleController($translator);
        $metadataController = new MetadataController($twig, $sessions, $statuses, $taskTypes, $customers, $translator);
        $customFieldController = new CustomFieldController($twig, $sessions, $customFields, $translator);
        $statusDefaultsController = new StatusDefaultsController($sessions, $userBootstrap);
        $notificationController = new NotificationSettingsController(
            $twig,
            $sessions,
            $notificationSettings,
            $telegramLinkTokens,
            $telegramSender,
            $translator,
            $telegramBotName,
            $appTimezone,
        );
        $notificationAdminController = new NotificationAdminController(
            $twig,
            $sessions,
            $users,
            $smtpSettings,
            $emailSender,
            $telegramSender,
            $notificationSecret,
            $translator,
            $appUrl,
            $telegramWebhookSecret,
            $telegramBotToken,
        );
        $telegramWebhookController = new TelegramWebhookController(
            $notificationSettings,
            $telegramLinkTokens,
            $telegramSender,
            $translator,
            $telegramWebhookSecret,
        );
        $requireAuth = new RequireAuthMiddleware($sessions);
        $requireAdmin = new RequireAdminMiddleware($sessions, $app->getResponseFactory(), $translator);
        $sanitizeTaskDescription = new SanitizeTaskDescriptionMiddleware($descriptionSanitizer);

        $app->get('/', static function (ServerRequestInterface $request, ResponseInterface $response) use ($sessions): ResponseInterface {
            return $response->withHeader('Location', $sessions->isAuthenticated() ? '/dashboard' : '/login')->withStatus(302);
        });
        $app->get('/health', static function (ServerRequestInterface $request, ResponseInterface $response) use ($db): ResponseInterface {
            try {
                $statement = $db->query('SELECT 1');
                if ($statement === false) {
                    throw new RuntimeException('Database health check failed.');
                }
                $statement->fetchColumn();
                $payload = '{"status":"ok"}';
                $status = 200;
            } catch (Throwable) {
                $payload = '{"status":"unhealthy"}';
                $status = 503;
            }
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        });

        $app->get('/login', [$authController, 'showLogin']);
        $app->post('/login', [$authController, 'login']);
        $app->post('/logout', [$authController, 'logout'])->add($requireAuth);
        $app->post('/locale', [$localeController, 'switch']);

        $app->get('/dashboard', [$dashboardController, 'show'])->add($requireAuth);
        $app->get('/tasks', [$taskController, 'index'])->add($requireAuth);
        $app->get('/tasks/new', [$taskController, 'new'])->add($requireAuth);
        $app->post('/tasks', [$taskController, 'create'])->add($sanitizeTaskDescription)->add($requireAuth);
        $app->post('/tasks/quick-add', [$quickTaskController, 'create'])->add($requireAuth);
        $app->get('/tasks/{id:[0-9]+}/edit', [$taskController, 'edit'])->add($requireAuth);
        $app->post('/tasks/{id:[0-9]+}', [$taskController, 'update'])->add($sanitizeTaskDescription)->add($requireAuth);
        $app->post('/tasks/{id:[0-9]+}/delete', [$taskDeleteController, 'delete'])->add($requireAuth);
        $app->post('/tasks/{id:[0-9]+}/status', [$taskStatusController, 'move'])->add($requireAuth);
        $app->post('/tasks/{taskId:[0-9]+}/attachments', [$attachmentController, 'upload'])->add($requireAuth);
        $app->get('/tasks/{taskId:[0-9]+}/attachments/{attachmentId:[0-9]+}', [$attachmentController, 'download'])->add($requireAuth);
        $app->post('/tasks/{taskId:[0-9]+}/attachments/{attachmentId:[0-9]+}/delete', [$attachmentController, 'delete'])->add($requireAuth);
        $app->get('/api/customers/search', [$customerSearchController, 'search'])->add($requireAuth);
        $app->get('/board', [$taskController, 'board'])->add($requireAuth);
        $app->get('/calendar', [$calendarController, 'show'])->add($requireAuth);

        $app->get('/metadata', [$metadataController, 'index'])->add($requireAuth);
        $app->post('/metadata/statuses', [$metadataController, 'createStatus'])->add($requireAuth);
        $app->post('/metadata/statuses/restore-defaults', [$statusDefaultsController, 'restore'])->add($requireAuth);
        $app->post('/metadata/statuses/{id:[0-9]+}', [$metadataController, 'updateStatus'])->add($requireAuth);
        $app->post('/metadata/statuses/{id:[0-9]+}/default', [$metadataController, 'setDefaultStatus'])->add($requireAuth);
        $app->post('/metadata/statuses/{id:[0-9]+}/completion', [$metadataController, 'setCompletionStatus'])->add($requireAuth);
        $app->post('/metadata/statuses/{id:[0-9]+}/move', [$metadataController, 'moveStatus'])->add($requireAuth);
        $app->post('/metadata/statuses/{id:[0-9]+}/delete', [$metadataController, 'deleteStatus'])->add($requireAuth);
        $app->post('/metadata/types', [$metadataController, 'createType'])->add($requireAuth);
        $app->post('/metadata/types/{id:[0-9]+}', [$metadataController, 'updateType'])->add($requireAuth);
        $app->post('/metadata/types/{id:[0-9]+}/move', [$metadataController, 'moveType'])->add($requireAuth);
        $app->post('/metadata/types/{id:[0-9]+}/delete', [$metadataController, 'deleteType'])->add($requireAuth);
        $app->post('/metadata/customers', [$metadataController, 'createCustomer'])->add($requireAuth);
        $app->post('/metadata/customers/{id:[0-9]+}', [$metadataController, 'updateCustomer'])->add($requireAuth);
        $app->post('/metadata/customers/{id:[0-9]+}/delete', [$metadataController, 'deleteCustomer'])->add($requireAuth);

        $app->get('/custom-fields', [$customFieldController, 'index'])->add($requireAuth);
        $app->post('/custom-fields', [$customFieldController, 'create'])->add($requireAuth);
        $app->post('/custom-fields/{id:[0-9]+}', [$customFieldController, 'update'])->add($requireAuth);
        $app->post('/custom-fields/{id:[0-9]+}/move', [$customFieldController, 'move'])->add($requireAuth);
        $app->post('/custom-fields/{id:[0-9]+}/delete', [$customFieldController, 'delete'])->add($requireAuth);

        $app->get('/settings/notifications', [$notificationController, 'show'])->add($requireAuth);
        $app->post('/settings/notifications', [$notificationController, 'save'])->add($requireAuth);
        $app->post('/settings/notifications/telegram-link', [$notificationController, 'generateTelegramLink'])->add($requireAuth);
        $app->post('/settings/notifications/telegram-test', [$notificationController, 'testTelegram'])->add($requireAuth);
        $app->post('/settings/notifications/telegram-disconnect', [$notificationController, 'disconnectTelegram'])->add($requireAuth);
        $app->get('/admin/notifications', [$notificationAdminController, 'show'])->add($requireAdmin)->add($requireAuth);
        $app->post('/admin/notifications/smtp', [$notificationAdminController, 'saveSmtp'])->add($requireAdmin)->add($requireAuth);
        $app->post('/admin/notifications/smtp-test', [$notificationAdminController, 'testEmail'])->add($requireAdmin)->add($requireAuth);
        $app->post('/admin/notifications/telegram-webhook', [$notificationAdminController, 'setupTelegramWebhook'])->add($requireAdmin)->add($requireAuth);
        $app->post('/telegram/webhook', [$telegramWebhookController, 'handle']);

        $app->add(new CsrfMiddleware($app->getResponseFactory(), $translator, ['/telegram/webhook']));
        $app->add(TwigMiddleware::create($app, $twig));
        $app->add(new PersistentLoginMiddleware($sessions, $persistentLogin, $users, $cookiePolicy, $rememberLifetime, $rememberCookieName));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware($debug, true, true);
        $app->run();
    }

    private function configureTimezone(string $timezone): string
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new RuntimeException('APP_TIMEZONE must be a valid PHP/IANA timezone.', 0, $exception);
        }
        if (!date_default_timezone_set($zone->getName())) {
            throw new RuntimeException('Unable to configure APP_TIMEZONE.');
        }
        return (new DateTimeImmutable('now', $zone))->format('P');
    }

    private function configureDatabaseTimezone(PDO $db, string $timezoneOffset): void
    {
        if (preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/D', $timezoneOffset) !== 1) {
            throw new RuntimeException('Unable to derive a valid database timezone offset.');
        }
        $quoted = $db->quote($timezoneOffset);
        if ($quoted === false || $db->exec('SET time_zone = ' . $quoted) === false) {
            throw new RuntimeException('Unable to configure the database session timezone.');
        }
    }

    private function startSession(bool $secure, string $sameSite): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_name($this->env('SESSION_NAME', 'tms_session'));
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => $sameSite]);
        if (!session_start()) {
            throw new RuntimeException('Unable to start PHP session.');
        }
    }

    private function requiredEnv(string $name): string
    {
        $value = $this->env($name, '');
        if ($value === '') {
            throw new RuntimeException(sprintf('Required environment variable %s is not set.', $name));
        }
        return $value;
    }

    private function positiveIntEnv(string $name, int $default): int
    {
        $raw = $this->env($name, (string) $default);
        if (!ctype_digit($raw) || (int) $raw < 1) {
            throw new RuntimeException(sprintf('%s must be a positive integer.', $name));
        }
        return (int) $raw;
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? $default;
        }
        return is_string($value) ? $value : $default;
    }

    private function boolEnv(string $name, bool $default): bool
    {
        $value = strtolower($this->env($name, $default ? 'true' : 'false'));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
