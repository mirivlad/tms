<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Application\RegistrationService;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class RegistrationController
{
    public function __construct(
        private readonly Twig $view,
        private readonly RegistrationService $registration,
        private readonly SessionManager $sessions,
        private readonly Translator $translator,
        private readonly bool $enabled,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->enabled) {
            return $this->view->render($response, 'auth/register_disabled.twig', [
                'csrf_token' => $this->csrfToken($request),
            ])->withStatus(404);
        }
        return $this->view->render($response, 'auth/register.twig', [
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->enabled) {
            return $response->withStatus(404);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $username = is_string($body['username'] ?? null) ? trim((string) $body['username']) : '';
        $email = is_string($body['email'] ?? null) ? trim((string) $body['email']) : '';
        $password = is_string($body['password'] ?? null) ? (string) $body['password'] : '';
        $confirm = is_string($body['password_confirm'] ?? null) ? (string) $body['password_confirm'] : '';

        $error = $this->validate($username, $email, $password, $confirm);
        if ($error !== null) {
            return $this->renderForm($request, $response, $username, $email, $error, 422);
        }
        try {
            $result = $this->registration->register($username, $email, $password, $this->now());
            $_SESSION['registration_result'] = [
                'email' => $result['user']->email,
                'email_sent' => $result['email_sent'],
            ];
            return $response->withHeader('Location', '/register/success')->withStatus(302);
        } catch (DomainException) {
            return $this->renderForm($request, $response, $username, $email, $this->translator->trans('registration.identity_exists'), 422);
        }
    }

    public function success(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $_SESSION['registration_result'] ?? null;
        unset($_SESSION['registration_result']);
        if (!is_array($result) || !is_string($result['email'] ?? null)) {
            return $response->withHeader('Location', '/register')->withStatus(302);
        }
        return $this->view->render($response, 'auth/register_success.twig', [
            'csrf_token' => $this->csrfToken($request),
            'email' => $result['email'],
            'email_sent' => (bool) ($result['email_sent'] ?? false),
        ]);
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $token = is_string($query['token'] ?? null) ? (string) $query['token'] : '';
        $user = $token === '' ? null : $this->registration->verify($token, $this->now());
        if ($user !== null && $user->isApproved && $user->isActive) {
            $this->sessions->establish($user);
        }
        return $this->view->render($response, 'auth/verify_email.twig', [
            'csrf_token' => $this->csrfToken($request),
            'success' => $user !== null,
            'auto_login' => $user !== null && $user->isApproved && $user->isActive,
        ])->withStatus($user === null ? 400 : 200);
    }

    private function validate(string $username, string $email, string $password, string $confirm): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $username) !== 1) {
            return $this->translator->trans('registration.username_invalid');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->translator->trans('registration.email_invalid');
        }
        if (strlen($password) < 12) {
            return $this->translator->trans('registration.password_min');
        }
        if ($password !== $confirm) {
            return $this->translator->trans('registration.password_mismatch');
        }
        return null;
    }

    private function renderForm(ServerRequestInterface $request, ResponseInterface $response, string $username, string $email, string $error, int $status): ResponseInterface
    {
        return $this->view->render($response, 'auth/register.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $username,
            'email' => $email,
            'error' => $error,
        ])->withStatus($status);
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
