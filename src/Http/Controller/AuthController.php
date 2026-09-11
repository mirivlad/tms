<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Http\CookiePolicy;
use Tms\I18n\Translator;
use Tms\Security\PasswordAuthenticator;
use Tms\Security\PersistentLoginService;
use Tms\Security\SessionManager;

final class AuthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PasswordAuthenticator $authenticator,
        private readonly SessionManager $sessions,
        private readonly PersistentLoginService $persistentLogin,
        private readonly CookiePolicy $cookiePolicy,
        private readonly DateInterval $rememberLifetime,
        private readonly string $rememberCookieName,
        private readonly Translator $translator,
    ) {
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->sessions->isAuthenticated()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return $this->view->render($response, 'auth/login.twig', [
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];

        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $remember = isset($data['remember']) && (string) $data['remember'] === '1';

        $user = $this->authenticator->authenticate($username, $password);
        if ($user === null) {
            return $this->view->render($response, 'auth/login.twig', [
                'csrf_token' => $this->csrfToken($request),
                'username' => $username,
                'error' => $this->translator->trans('auth.invalid_credentials'),
            ])->withStatus(401);
        }

        // Authentication deliberately clears attacker-controlled pre-login session
        // state. The locale is the one benign preference explicitly restored after
        // the session id is rotated.
        $locale = $this->translator->locale();
        $this->sessions->establish($user);
        $this->translator->setLocale($locale);

        $existingCookie = $_COOKIE[$this->rememberCookieName] ?? null;
        if (is_string($existingCookie) && $existingCookie !== '') {
            $this->persistentLogin->revoke($existingCookie);
        }

        if ($remember) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $token = $this->persistentLogin->issue($user->id, $now);
            setcookie(
                $this->rememberCookieName,
                $token->cookieValue(),
                $this->cookiePolicy->persistent($now->add($this->rememberLifetime)),
            );
        } else {
            setcookie($this->rememberCookieName, '', $this->cookiePolicy->expired());
        }

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cookie = $_COOKIE[$this->rememberCookieName] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            $this->persistentLogin->revoke($cookie);
        }

        setcookie($this->rememberCookieName, '', $this->cookiePolicy->expired());
        $locale = $this->translator->locale();
        $this->sessions->clear();
        $this->translator->setLocale($locale);

        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
