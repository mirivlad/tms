<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DateTimeZone;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\User\UserRepository;
use Tms\Domain\UserPreference\UserPreferenceRepository;
use Tms\I18n\Translator;
use Tms\Security\RememberTokenRepository;
use Tms\Security\SessionManager;

final class ProfileController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
        private readonly UserPreferenceRepository $preferences,
        private readonly RememberTokenRepository $rememberTokens,
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $notice = $_SESSION['profile_notice'] ?? null;
        unset($_SESSION['profile_notice']);
        if (!is_array($notice) || !is_string($notice['message'] ?? null)) {
            $notice = null;
        }
        return $this->view->render($response, 'settings/profile.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'user' => $user,
            'preferences' => $this->preferences->getForUser($userId),
            'timezones' => DateTimeZone::listIdentifiers(),
            'themes' => UserPreferenceRepository::THEMES,
            'notice' => $notice,
        ]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        try {
            $username = is_string($body['username'] ?? null) ? trim((string) $body['username']) : '';
            if (preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $username) !== 1) {
                throw new DomainException($this->translator->trans('profile.username_invalid'));
            }
            $timezone = is_string($body['timezone'] ?? null) ? (string) $body['timezone'] : '';
            $theme = is_string($body['theme'] ?? null) ? UserPreferenceRepository::normalizeTheme((string) $body['theme']) : '';
            if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new DomainException($this->translator->trans('profile.timezone_invalid'));
            }
            if (!in_array($theme, UserPreferenceRepository::THEMES, true)) {
                throw new DomainException($this->translator->trans('profile.theme_invalid'));
            }
            $newPasswordHash = $this->validatedPasswordHash($user->passwordHash, $body);

            if ($username !== $user->username) {
                $this->users->updateUsername($userId, $username);
                $user = $this->users->findById($userId) ?? $user;
                $this->sessions->refreshIdentity($user);
            }
            $this->preferences->saveForUser($userId, $timezone, $theme);
            if ($newPasswordHash !== null) {
                $this->users->replacePasswordHash($userId, $newPasswordHash);
                $this->rememberTokens->deleteAllForUser($userId);
            }

            $_SESSION['profile_notice'] = [
                'kind' => 'success',
                'message' => $this->translator->trans('profile.saved'),
            ];
        } catch (DomainException $error) {
            $_SESSION['profile_notice'] = ['kind' => 'error', 'message' => $error->getMessage()];
        }

        return $response->withHeader('Location', '/settings/profile')->withStatus(302);
    }

    /** @param array<string, mixed> $body */
    private function validatedPasswordHash(string $passwordHash, array $body): ?string
    {
        $current = is_string($body['current_password'] ?? null) ? (string) $body['current_password'] : '';
        $new = is_string($body['new_password'] ?? null) ? (string) $body['new_password'] : '';
        $confirm = is_string($body['confirm_new_password'] ?? null) ? (string) $body['confirm_new_password'] : '';

        // Password managers may autofill only the current-password field on a profile form.
        // Treat password change as requested only when the user enters a new password or confirmation.
        if ($new === '' && $confirm === '') {
            return null;
        }
        if ($current === '' || $new === '' || $confirm === '') {
            throw new DomainException($this->translator->trans('profile.password_all_fields'));
        }
        if (!password_verify($current, $passwordHash)) {
            throw new DomainException($this->translator->trans('profile.password_current_wrong'));
        }
        if (strlen($new) < 12) {
            throw new DomainException($this->translator->trans('profile.password_min'));
        }
        if ($new !== $confirm) {
            throw new DomainException($this->translator->trans('profile.password_mismatch'));
        }
        return password_hash($new, PASSWORD_DEFAULT);
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
