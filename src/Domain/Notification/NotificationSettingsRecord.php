<?php

declare(strict_types=1);

namespace Tms\Domain\Notification;

final readonly class NotificationSettingsRecord
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $accountEmail,
        public bool $emailEnabled,
        public ?string $emailAddress,
        public bool $telegramEnabled,
        public ?string $telegramChatId,
        public ?string $telegramUsername,
        public bool $notifyTomorrow,
        public string $tomorrowTime,
        public bool $notifyUpcoming,
        public int $urgentMinutes,
        public int $highMinutes,
        public int $mediumMinutes,
        public int $lowMinutes,
        public bool $notifyOverdue,
        public string $overdueTime,
        public bool $notifyDigest,
        public string $digestTime,
    ) {
    }

    public function deliveryEmail(): string
    {
        $override = trim((string) $this->emailAddress);
        return $override !== '' ? $override : $this->accountEmail;
    }

    public function leadMinutesForPriority(int $priority): int
    {
        return match ($priority) {
            3 => $this->urgentMinutes,
            2 => $this->highMinutes,
            1 => $this->mediumMinutes,
            default => $this->lowMinutes,
        };
    }
}
