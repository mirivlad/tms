<?php

declare(strict_types=1);

namespace Tms\Application;

use GuzzleHttp\ClientInterface;
use PDO;
use Throwable;

/**
 * Forwards canonical TMS inbox events to a trusted ChimePort application.
 *
 * No ChimePort accounts are created. The stable, app-scoped recipient identifier
 * is the immutable TMS numeric user ID, not a password or username hash.
 * The durable cursor is advanced only after a 2xx response (or for inactive users).
 * Replayed delivery is safe because ChimePort enforces Idempotency-Key.
 *
 * The first run starts at the current inbox tail to avoid replaying years of
 * historical notifications into newly enabled integrations.
 */
final class ChimePortBridgeRunner
{
    public function __construct(
        private readonly PDO $db,
        private readonly ClientInterface $http,
        private readonly string $chimePortUrl,
        private readonly string $appToken,
        private readonly string $tmsUrl,
    ) {
        if (filter_var($chimePortUrl, FILTER_VALIDATE_URL) === false
            || !str_starts_with($chimePortUrl, 'https://')
            || filter_var($tmsUrl, FILTER_VALIDATE_URL) === false
            || !str_starts_with($tmsUrl, 'https://')
            || $appToken === '') {
            throw new \InvalidArgumentException('ChimePort bridge requires HTTPS URLs and a nonempty app token.');
        }
    }

    /** @return array{attempted:int,sent:int,failed:int,skipped:int} */
    public function run(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stats = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $position = $this->db->query('SELECT last_id FROM chimeport_bridge_state WHERE id = 1')->fetchColumn();

        if ($position === false) {
            $tail = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM internal_notifications')->fetchColumn();
            $init = $this->db->prepare('INSERT INTO chimeport_bridge_state (id, last_id) VALUES (1, :last_id)');
            $init->execute(['last_id' => $tail]);
            return $stats;
        }

        $statement = $this->db->prepare(
            'SELECT n.id, n.user_id, n.actor_username, n.notification_type,
                    n.context_label, n.body_preview, n.target_url,
                    u.is_active, u.approved_at
             FROM internal_notifications n
             INNER JOIN users u ON u.id = n.user_id
             WHERE n.id > :last_id
             ORDER BY n.id ASC
             LIMIT ' . $limit
        );
        $statement->execute(['last_id' => (int) $position]);
        $advance = $this->db->prepare('UPDATE chimeport_bridge_state SET last_id = :last_id WHERE id = 1');

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int) $row['id'];
            if ((int) $row['is_active'] !== 1 || $row['approved_at'] === null) {
                $stats['skipped']++;
            } else {
                $stats['attempted']++;
                if (!$this->deliver($row)) {
                    $stats['failed']++;
                    break; // Keep the current and later events for a subsequent retry.
                }
                $stats['sent']++;
            }
            $advance->execute(['last_id' => $id]);
        }

        return $stats;
    }

    /** @param array<string,mixed> $row */
    private function deliver(array $row): bool
    {
        $type = (string) $row['notification_type'];
        $context = (string) $row['context_label'];
        $actor = (string) $row['actor_username'];
        $preview = trim((string) $row['body_preview']);
        $body = $actor !== '' ? $actor : $type;
        if ($preview !== '') {
            $body .= ': ' . $preview;
        }
        $target = (string) $row['target_url'];
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return false;
        }
        $priority = str_contains($type, 'mention') || str_contains($type, 'overdue') ? 'high' : 'normal';

        try {
            $response = $this->http->request('POST', rtrim($this->chimePortUrl, '/') . '/api/v1/external/messages', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->appToken,
                    'Idempotency-Key' => 'tms-inbox-' . (string) $row['id'],
                ],
                'json' => [
                    'recipient' => (string) $row['user_id'],
                    'title' => mb_substr('TMS: ' . $context, 0, 200),
                    'body' => mb_substr($body, 0, 4000),
                    'priority' => $priority,
                    'url' => rtrim($this->tmsUrl, '/') . $target,
                ],
                'connect_timeout' => 4,
                'timeout' => 8,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
            return in_array($response->getStatusCode(), [200, 201], true);
        } catch (Throwable) {
            // Transport errors may contain Authorization headers; never log them.
            error_log('ChimePort bridge: delivery failed; event retained for retry.');
            return false;
        }
    }
}
