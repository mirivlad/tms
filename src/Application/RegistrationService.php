<?php

declare(strict_types=1);

namespace Tms\Application;

use DateInterval;
use DateTimeImmutable;
use Tms\Domain\User\UserRecord;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Security\EmailVerificationTokenRepository;

final class RegistrationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EmailVerificationTokenRepository $tokens,
        private readonly UserBootstrapService $bootstrap,
        private readonly EmailSender $emailSender,
        private readonly Translator $translator,
        private readonly string $appUrl,
        private readonly bool $autoApproveAfterVerification,
        private readonly DateInterval $lifetime = new DateInterval('P1D'),
    ) {
    }

    /** @return array{user:UserRecord,email_sent:bool} */
    public function register(string $username, string $email, string $password, DateTimeImmutable $now): array
    {
        $userId = $this->users->createUser(
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            true,
            false,
            false,
        );
        $this->bootstrap->ensureDefaults($userId);
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new \RuntimeException('Created user cannot be loaded.');
        }
        return ['user' => $user, 'email_sent' => $this->sendVerification($user, $now)];
    }

    public function invalidateVerification(int $userId): void
    {
        $this->tokens->deleteAllForUser($userId);
    }

    public function sendVerification(UserRecord $user, DateTimeImmutable $now): bool
    {
        if ($user->isEmailVerified) {
            return true;
        }
        $token = $this->tokens->issue($user->id, $now, $this->lifetime);
        $url = rtrim($this->appUrl, '/') . '/verify-email?token=' . rawurlencode($token->value());
        $subject = $this->translator->trans('registration.mail_subject');
        $text = $this->translator->trans('registration.mail_text', ['url' => $url]);
        $html = '<p>' . htmlspecialchars($this->translator->trans('registration.mail_intro'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';
        return $this->emailSender->send($user->email, $user->username, $subject, $html, $text);
    }

    public function verify(string $token, DateTimeImmutable $now): ?UserRecord
    {
        $userId = $this->tokens->consume($token, $now);
        if ($userId === null) {
            return null;
        }
        $this->users->setEmailVerified($userId, true);
        if ($this->autoApproveAfterVerification) {
            $this->users->setApproved($userId, true);
        }
        $this->tokens->deleteAllForUser($userId);
        return $this->users->findById($userId);
    }
}
