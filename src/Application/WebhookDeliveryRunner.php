<?php

declare(strict_types=1);

namespace Tms\Application;

use DateTimeImmutable;
use JsonException;
use Tms\Domain\Event\DomainEvent;
use Tms\Domain\Event\DomainEventRepository;
use Tms\Domain\Webhook\WebhookDeliveryRepository;
use Tms\Domain\Webhook\WebhookSubscriptionRepository;
use Tms\Infrastructure\WebhookSender;

final class WebhookDeliveryRunner
{
    private const MAX_ATTEMPTS = 8;

    /** @var array<int, int> */
    private const RETRY_DELAYS = [
        1 => 60,
        2 => 300,
        3 => 900,
        4 => 3600,
        5 => 21600,
        6 => 86400,
        7 => 172800,
    ];

    public function __construct(
        private readonly WebhookSubscriptionRepository $subscriptions,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly DomainEventRepository $events,
        private readonly WebhookSender $sender,
    ) {
    }

    /** @return array{subscriptions:int,recovered:int,attempted:int,delivered:int,retried:int,failed:int} */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $stats = [
            'subscriptions' => 0,
            'recovered' => 0,
            'attempted' => 0,
            'delivered' => 0,
            'retried' => 0,
            'failed' => 0,
        ];

        foreach ($this->subscriptions->listActive() as $subscription) {
            $stats['subscriptions']++;
            $stats['recovered'] += $this->deliveries->recoverMissing($subscription);
        }

        $nowSql = $now->format('Y-m-d H:i:s.u');
        foreach ($this->deliveries->listDue($nowSql) as $delivery) {
            $subscription = $this->subscriptions->find($delivery->subscriptionId);
            $event = $this->events->find($delivery->eventId);
            if ($subscription === null || !$subscription->isActive || $event === null) {
                continue;
            }

            $stats['attempted']++;
            $attempt = $delivery->attemptCount + 1;
            try {
                $body = $this->body($event);
                $timestamp = (string) $now->getTimestamp();
                $signature = hash_hmac(
                    'sha256',
                    $timestamp . '.' . $body,
                    $this->subscriptions->secret($subscription),
                );
                $result = $this->sender->send(
                    $subscription->endpointUrl,
                    [
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'TMS-Webhook/1',
                        'X-TMS-Webhook-Version' => '1',
                        'X-TMS-Delivery-Id' => (string) $delivery->id,
                        'X-TMS-Event-Id' => $event->id,
                        'X-TMS-Event-Type' => $event->type,
                        'X-TMS-Timestamp' => $timestamp,
                        'X-TMS-Signature' => 'v1=' . $signature,
                    ],
                    $body,
                );
            } catch (\Throwable $error) {
                $result = new \Tms\Infrastructure\WebhookDeliveryResult(false, null, $error->getMessage());
            }

            if ($result->success && $result->httpStatus !== null) {
                $this->deliveries->markSucceeded(
                    $delivery->id,
                    $attempt,
                    $result->httpStatus,
                    $nowSql,
                );
                $stats['delivered']++;
                continue;
            }

            $error = $result->error ?? 'Webhook delivery failed.';
            if ($attempt >= self::MAX_ATTEMPTS) {
                $this->deliveries->markFailed(
                    $delivery->id,
                    $attempt,
                    $result->httpStatus,
                    $error,
                    $nowSql,
                );
                $stats['failed']++;
                continue;
            }

            $delay = self::RETRY_DELAYS[$attempt] ?? 172800;
            $this->deliveries->scheduleRetry(
                $delivery->id,
                $attempt,
                $now->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s.u'),
                $result->httpStatus,
                $error,
                $nowSql,
            );
            $stats['retried']++;
        }

        return $stats;
    }

    private function body(DomainEvent $event): string
    {
        try {
            return json_encode([
                'id' => $event->id,
                'type' => $event->type,
                'schema_version' => $event->schemaVersion,
                'occurred_at' => $event->occurredAt,
                'actor' => [
                    'user_id' => $event->actorUserId,
                    'username' => $event->actorUsername,
                ],
                'subject' => [
                    'task_id' => $event->taskId,
                    'project_id' => $event->projectId,
                    'comment_id' => $event->commentId,
                ],
                'visibility' => [
                    'user_id' => $event->visibilityUserId,
                    'team_id' => $event->visibilityTeamId,
                ],
                'data' => $event->payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new \RuntimeException('Unable to encode webhook payload.', 0, $error);
        }
    }
}
