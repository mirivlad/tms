<?php

declare(strict_types=1);

namespace Tms\Application;

use DateInterval;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Throwable;
use Tms\Domain\User\UserRepository;
use Tms\Http\Controller\AuthController;
use Tms\Http\Controller\DashboardController;
use Tms\Http\CookiePolicy;
use Tms\Http\Middleware\CsrfMiddleware;
use Tms\Http\Middleware\PersistentLoginMiddleware;
use Tms\Http\Middleware\RequireAuthMiddleware;
use Tms\Infrastructure\Database;
use Tms\Infrastructure\NativeSessionIdRegenerator;
use Tms\Security\PasswordAuthenticator;
use Tms\Security\PersistentLoginService;
use Tms\Security\RememberTokenRepository;
use Tms\Security\SessionManager;

final class ApplicationFactory
{
    /**
     * @return App<null>
     */
    public function create(): App
    {
        $debug = $this->boolEnv('APP_DEBUG', false);
        $sameSite = $this->env('SESSION_SAMESITE', 'Lax');
        $secureCookies = $this->boolEnv('SESSION_SECURE', true);
        $rememberCookieName = $this->env('REMEMBER_COOKIE_NAME', 'tms_remember');
        $rememberDays = max(1, (int) $this->env('REMEMBER_DAYS', '30'));
        $rememberLifetime = new DateInterval('P' . $rememberDays . 'D');

        $this->startSession($secureCookies, $sameSite);

        $db = (new Database([
            'host' => $this->requiredEnv('DB_HOST'),
            'port' => $this->env('DB_PORT', '3306'),
            'name' => $this->requiredEnv('DB_NAME'),
            'user' => $this->requiredEnv('DB_USER'),
            'password' => $this->requiredEnv('DB_PASS'),
        ]))->connect();

        $app = SlimAppFactory::create();
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates', ['cache' => false]);

        $users = new UserRepository($db);
        $sessions = new SessionManager(new NativeSessionIdRegenerator());
        $passwordAuthenticator = new PasswordAuthenticator($users);
        $rememberTokens = new RememberTokenRepository($db);
        $persistentLogin = new PersistentLoginService($rememberTokens, $rememberLifetime);
        $cookiePolicy = new CookiePolicy($secureCookies, $sameSite);

        $authController = new AuthController(
            $twig,
            $passwordAuthenticator,
            $sessions,
            $persistentLogin,
            $cookiePolicy,
            $rememberLifetime,
            $rememberCookieName,
        );
        $dashboardController = new DashboardController($twig, $sessions);
        $requireAuth = new RequireAuthMiddleware($sessions);

        $app->get('/', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($sessions): ResponseInterface {
            $location = $sessions->isAuthenticated() ? '/dashboard' : '/login';
            return $response->withHeader('Location', $location)->withStatus(302);
        });

        $app->get('/health', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($db): ResponseInterface {
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
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($status);
        });

        $app->get('/login', [$authController, 'showLogin']);
        $app->post('/login', [$authController, 'login']);
        $app->post('/logout', [$authController, 'logout'])->add($requireAuth);
        $app->get('/dashboard', [$dashboardController, 'show'])->add($requireAuth);

        // Slim middleware is executed in reverse registration order. CSRF is
        // registered first so body parsing and persistent-login restoration run
        // before it, while CSRF still wraps all state-changing application routes.
        $app->add(new CsrfMiddleware($app->getResponseFactory()));
        $app->add(TwigMiddleware::create($app, $twig));
        $app->add(new PersistentLoginMiddleware(
            $sessions,
            $persistentLogin,
            $users,
            $cookiePolicy,
            $rememberLifetime,
            $rememberCookieName,
        ));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware($debug, true, true);

        return $app;
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
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

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
