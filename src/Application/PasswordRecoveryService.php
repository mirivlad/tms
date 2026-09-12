<?php

declare(strict_types=1);

namespace Tms\Application;

use DateInterval;
use DateTimeImmutable;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\User\UserRecord;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;
use Tms\Security\PasswordResetTokenRepository;
use Tms\Security\RememberTokenRepository;

final class PasswordRecoveryService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly RememberTokenRepository $rememberTokens,
        private readonly NotificationSettingsRepository $notificationSettings,
        private readonly EmailSender $emailSender,
        private readonly TelegramSender $telegramSender,
        private readonly Translator $translator,
        private readonly string $appUrl,
        private readonly DateInterval $lifetime = new DateInterval('PT1H'),
    ) {
    }

    public function request(string $identifier, DateTimeImmutable $now): void
    {
        $user = $this->users->findByIdentifier(trim($identifier));
        if ($user === null || !$user->isActive || !$user->isApproved) {
            return;
        }

        $token = $this->resetTokens->issue($user->id, $now, $this->lifetime);
        $url = rtrim($this->appUrl, '/') . '/reset-password?token=' . rawurlencode($token->value());
        $subject = $this->translator->trans('recovery.mail_subject');
        $text = $this->translator->trans('recovery.mail_text', ['url' => $url]);
        $html = '<p>' . htmlspecialchars($this->translator->trans('recovery.mail_intro'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' 
            . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';

        $this->emailSender->send($user->email, $user->username, $subject, $html, $text);

        $settings = $this->notificationSettings->getForUser($user->id);
        if ($settings->telegramChatId !== null && $settings->telegramChatId !== '') {
            $this->telegramSender->send(
                $settings->telegramChatId,
                $this->translator->trans('recovery.telegram_text', ['url' => $url]),
            );
        }
    }

    public function resolve(string $token, DateTimeImmutable $now): ?UserRecord
    {
        $userId = $this->resetTokens->findValidUserId($token, $now);
        return $userId === null ? null : $this->users->findById($userId);
    }

    public function reset(string $token, string $newPassword, DateTimeImmutable $now): bool
    {
        $userId = $this->resetTokens->consume($token, $now);
        if ($userId === null) {
            return false;
        }
        $this->users->replacePasswordHash($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        $this->rememberTokens->deleteAllForUser($userId);
        $this->resetTokens->deleteAllForUser($userId);
        return true;
    }
}
