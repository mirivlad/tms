<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Team\TeamInvitationRecord;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class TeamInvitationDeliveryService
{
    public function __construct(
        private readonly NotificationSettingsRepository $settings,
        private readonly EmailSender $email,
        private readonly TelegramSender $telegram,
        private readonly string $appUrl,
        private readonly Translator $translator,
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
            ? $this->translator->trans('teams.invite_external_by', [
                'username' => $inviter,
                'team' => $invitation->teamName,
            ])
            : $this->translator->trans('teams.invite_external', ['team' => $invitation->teamName]);
        $open = $this->translator->trans('teams.invite_external_open');
        $text = $lead . "\n" . $open . ': ' . $url;

        if ($settings->emailEnabled) {
            $stats['attempted']++;
            $subject = $this->translator->trans(
                'teams.invite_external_subject',
                ['team' => $invitation->teamName],
            );
            $html = '<p>' . htmlspecialchars($lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($open, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';
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
