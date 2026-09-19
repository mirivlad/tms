<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Team\TeamInvitationRecord;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class TeamInvitationDeliveryService
{
    public function __construct(
        private readonly NotificationSettingsRepository $settings,
        private readonly EmailSender $email,
        private readonly TelegramSender $telegram,
        private readonly string $appUrl,
    ) {
    }

    /** @return array{attempted:int,sent:int} */
    public function deliver(TeamInvitationRecord $invitation): array
    {
        $settings = $this->settings->getForUser($invitation->invitedUserId);
        $stats = ['attempted' => 0, 'sent' => 0];
        $url = rtrim($this->appUrl, '/') . '/invitations';
        $inviter = trim((string) $invitation->invitedByUsername);
        $lead = $inviter !== ''
            ? $inviter . ' invited you to join the team "' . $invitation->teamName . '".'
            : 'You were invited to join the team "' . $invitation->teamName . '".';
        $text = $lead . "\nOpen TMS to accept or decline: " . $url;

        if ($settings->emailEnabled) {
            $stats['attempted']++;
            $subject = 'TMS: team invitation — ' . $invitation->teamName;
            $html = '<p>' . htmlspecialchars($lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . 'Open team invitations</a></p>';
            if ($this->email->send(
                $settings->deliveryEmail(),
                $settings->username,
                $subject,
                $html,
                $text,
            )) {
                $stats['sent']++;
            }
        }

        if ($settings->telegramEnabled && $settings->telegramChatId !== null) {
            $stats['attempted']++;
            if ($this->telegram->send($settings->telegramChatId, $text)) {
                $stats['sent']++;
            }
        }

        return $stats;
    }
}
