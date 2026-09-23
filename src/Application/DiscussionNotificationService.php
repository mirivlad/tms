<?php

declare(strict_types=1);

namespace Tms\Application;

use Tms\Domain\Discussion\DiscussionRepository;
use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventConsumer;
use Tms\Domain\Notification\InternalNotificationRepository;
use Tms\Domain\Notification\NotificationSettingsRepository;
use Tms\Domain\Team\TeamMemberRecord;
use Tms\Domain\Team\TeamRepository;
use Tms\I18n\Translator;
use Tms\Infrastructure\EmailSender;
use Tms\Infrastructure\TelegramSender;

final class DiscussionNotificationService implements DomainEventConsumer
{
    public function __construct(
        private readonly DiscussionRepository $discussions,
        private readonly TeamRepository $teams,
        private readonly InternalNotificationRepository $notifications,
        private readonly NotificationSettingsRepository $settings,
        private readonly EmailSender $email,
        private readonly TelegramSender $telegram,
        private readonly Translator $translator,
        private readonly string $appUrl,
    ) {
    }

    public function consume(DomainEvent $event): void
    {
        if (!in_array($event->type, ['discussion.comment.created', 'discussion.comment.updated'], true)
            || $event->actorUserId === null
            || $event->commentId === null) {
            return;
        }

        $this->processComment($event->actorUserId, $event->commentId);
    }

    public function processComment(int $actorUserId, int $commentId): void
    {
        $context = $this->discussions->notificationContextForMember($actorUserId, $commentId);
        if ($context === null || $context['author_user_id'] !== $actorUserId) {
            return;
        }

        $members = $this->teams->listMembers($actorUserId, $context['team_id']);
        if ($members === []) {
            return;
        }

        $membersById = [];
        foreach ($members as $member) {
            $membersById[$member->userId] = $member;
        }

        $notified = [];
        $parentAuthorId = $context['parent_author_user_id'];
        if ($parentAuthorId !== null
            && $parentAuthorId !== $actorUserId
            && isset($membersById[$parentAuthorId])) {
            $this->notify(
                recipient: $membersById[$parentAuthorId],
                type: 'discussion_reply',
                context: $context,
            );
            $notified[$parentAuthorId] = true;
        }

        $plain = $this->plainText($context['body_html']);
        foreach ($members as $member) {
            if ($member->userId === $actorUserId || isset($notified[$member->userId])) {
                continue;
            }
            if (!$this->containsMention($plain, $member->username)) {
                continue;
            }
            $this->notify(
                recipient: $member,
                type: 'discussion_mention',
                context: $context,
            );
            $notified[$member->userId] = true;
        }
    }

    /**
     * @param array{
     *   comment_id:int,
     *   author_user_id:int,
     *   author_username:string,
     *   body_html:string,
     *   parent_comment_id:?int,
     *   parent_author_user_id:?int,
     *   team_id:int,
     *   effective_project_id:int,
     *   project_id:?int,
     *   task_id:?int,
     *   context_label:string
     * } $context
     */
    private function notify(
        TeamMemberRecord $recipient,
        string $type,
        array $context,
    ): void {
        $targetUrl = $context['task_id'] !== null
            ? '/tasks/' . $context['task_id'] . '/discussion#comment-' . $context['comment_id']
            : '/projects/' . $context['effective_project_id'] . '/discussion#comment-' . $context['comment_id'];

        $dedupeKey = hash(
            'sha256',
            $type . ':' . $context['comment_id'] . ':' . $recipient->userId,
        );
        $preview = $this->preview($context['body_html']);
        $createdId = $this->notifications->create(
            userId: $recipient->userId,
            actorUserId: $context['author_user_id'],
            actorUsername: $context['author_username'],
            notificationType: $type,
            contextLabel: $context['context_label'],
            bodyPreview: $preview,
            targetUrl: $targetUrl,
            dedupeKey: $dedupeKey,
            projectId: $context['effective_project_id'],
            taskId: $context['task_id'],
            commentId: $context['comment_id'],
        );
        if ($createdId === null) {
            return;
        }

        $this->deliverExternal(
            recipient: $recipient,
            type: $type,
            actorUsername: $context['author_username'],
            contextLabel: $context['context_label'],
            preview: $preview,
            targetUrl: $targetUrl,
        );
    }

    private function deliverExternal(
        TeamMemberRecord $recipient,
        string $type,
        string $actorUsername,
        string $contextLabel,
        string $preview,
        string $targetUrl,
    ): void {
        $settings = $this->settings->getForUser($recipient->userId);
        $messageKey = $type === 'discussion_reply'
            ? 'notifications.discussion_reply'
            : 'notifications.discussion_mention';
        $heading = $this->translator->trans($messageKey, [
            'actor' => $actorUsername,
            'context' => $contextLabel,
        ]);
        $url = rtrim($this->appUrl, '/') . $targetUrl;
        $open = $this->translator->trans('notifications.collaboration_open');
        $text = $heading
            . ($preview !== '' ? "\n" . $preview : '')
            . "\n" . $open . ': ' . $url;
        $subject = $this->translator->trans(
            'notifications.collaboration_subject',
            ['context' => $contextLabel],
        );
        $html = '<p><strong>'
            . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</strong></p>';
        if ($preview !== '') {
            $html .= '<p>' . htmlspecialchars($preview, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        $html .= '<p><a href="'
            . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">' . htmlspecialchars($open, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';

        if ($settings->emailEnabled) {
            try {
                $this->email->send(
                    $settings->deliveryEmail(),
                    $settings->username,
                    $subject,
                    $html,
                    $text,
                );
            } catch (\Throwable $error) {
                error_log('TMS discussion email notification failed: ' . $error->getMessage());
            }
        }

        if ($settings->telegramEnabled && $settings->telegramChatId !== null) {
            try {
                $this->telegram->send($settings->telegramChatId, $text);
            } catch (\Throwable $error) {
                error_log('TMS discussion Telegram notification failed: ' . $error->getMessage());
            }
        }
    }

    private function containsMention(string $plain, string $username): bool
    {
        $quoted = preg_quote($username, '/');
        return preg_match(
            '/(?<![\pL\pN_.-])@' . $quoted . '(?![\pL\pN_.-])/iu',
            $plain,
        ) === 1;
    }

    private function plainText(string $html): string
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/\s+/u', ' ', trim($plain)) ?? trim($plain);
    }

    private function preview(string $html): string
    {
        $plain = $this->plainText($html);
        if (mb_strlen($plain) <= 240) {
            return $plain;
        }
        return rtrim(mb_substr($plain, 0, 237)) . '…';
    }
}
