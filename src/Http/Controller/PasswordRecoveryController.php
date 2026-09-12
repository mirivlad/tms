<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Application\PasswordRecoveryService;
use Tms\I18n\Translator;

final class PasswordRecoveryController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PasswordRecoveryService $recovery,
        private readonly Translator $translator,
    ) {
    }

    public function showForgot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'auth/forgot_password.twig', [
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    public function request(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];
        $identifier = trim((string) ($data['identifier'] ?? ''));
        if ($identifier !== '') {
            $this->recovery->request($identifier, $this->now());
        }
        return $this->view->render($response, 'auth/forgot_password.twig', [
            'csrf_token' => $this->csrfToken($request),
            'submitted' => true,
        ]);
    }

    public function showReset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $token = is_string($query['token'] ?? null) ? (string) $query['token'] : '';
        $user = $this->recovery->resolve($token, $this->now());
        return $this->view->render($response, 'auth/reset_password.twig', [
            'csrf_token' => $this->csrfToken($request),
            'token' => $token,
            'valid' => $user !== null,
            'username' => $user?->username,
        ])->withStatus($user === null ? 400 : 200);
    }

    public function reset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];
        $token = (string) ($data['token'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirm'] ?? '');
        $user = $this->recovery->resolve($token, $this->now());

        if ($user === null) {
            return $this->renderError($request, $response, $token, 'recovery.invalid_link', 400);
        }
        if (strlen($password) < 12) {
            return $this->renderError($request, $response, $token, 'recovery.password_min', 422, $user->username);
        }
        if ($password !== $confirm) {
            return $this->renderError($request, $response, $token, 'recovery.password_mismatch', 422, $user->username);
        }
        if (!$this->recovery->reset($token, $password, $this->now())) {
            return $this->renderError($request, $response, $token, 'recovery.invalid_link', 400);
        }
        return $this->view->render($response, 'auth/reset_password.twig', [
            'csrf_token' => $this->csrfToken($request),
            'success' => true,
        ]);
    }

    private function renderError(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $token,
        string $key,
        int $status,
        ?string $username = null,
    ): ResponseInterface {
        return $this->view->render($response, 'auth/reset_password.twig', [
            'csrf_token' => $this->csrfToken($request),
            'token' => $token,
            'valid' => $username !== null,
            'username' => $username,
            'error' => $this->translator->trans($key),
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
