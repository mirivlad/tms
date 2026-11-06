<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Infrastructure\ChimePortPushBridge;
use Tms\Security\PasswordAuthenticator;
use Throwable;

/**
 * API login terminates exclusively at TMS. A mobile app receives an opaque
 * one-use ChimePort ticket and never passes the TMS password to ChimePort.
 */
final class ChimePortMobileController
{
    public function __construct(
        private readonly PasswordAuthenticator $authenticator,
        private readonly ChimePortPushBridge $bridge,
        private readonly PDO $db,
        private readonly string $appToken,
        private readonly string $chimePortUrl,
        private readonly string $tmsUrl,
    ) {
    }

    public function discovery(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'protocol' => 'chimeport-trusted-app-v1',
            'issuer' => $this->tmsUrl,
            'chimeport_url' => $this->chimePortUrl,
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!$this->isJson($request) || !is_array($body)
            || !isset($body['username'], $body['password'], $body['code_challenge'])
            || !is_string($body['username']) || !is_string($body['password'])
            || !is_string($body['code_challenge'])) {
            return $this->json($response, ['error' => 'Invalid login request.'], 400);
        }
        $username = trim($body['username']);
        $password = $body['password'];
        $challenge = $body['code_challenge'];
        if ($username === '' || strlen($username) > 190 || strlen($password) > 512
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1) {
            return $this->json($response, ['error' => 'Invalid login request.'], 400);
        }

        $remoteIp = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $userBucket = hash_hmac('sha256', 'user:' . mb_strtolower($username), $this->appToken);
        $ipBucket = hash_hmac('sha256', 'ip:' . $remoteIp, $this->appToken);
        try {
            if ($this->blocked($userBucket, 12) || $this->blocked($ipBucket, 60)) {
                return $this->json($response, ['error' => 'Too many login attempts.'], 429);
            }
            $user = $this->authenticator->authenticate($username, $password);
            if ($user === null) {
                $this->recordFailure($userBucket);
                $this->recordFailure($ipBucket);
                return $this->json($response, ['error' => 'Invalid login or password.'], 401);
            }
            // Successful password verification clears the per-account failure bucket.
            $stmt = $this->db->prepare('DELETE FROM chimeport_mobile_login_attempts WHERE identifier = :id');
            $stmt->execute(['id' => $userBucket]);
            $ticket = $this->bridge->issueDeviceTicket($user->id, $challenge);
            return $this->json($response, [
                'ticket' => $ticket,
                'recipient' => (string) $user->id,
                'username' => $user->username,
                'chimeport_url' => $this->chimePortUrl,
            ]);
        } catch (Throwable) {
            return $this->json($response, ['error' => 'Notification login temporarily unavailable.'], 502);
        }
    }

    private function blocked(string $identifier, int $limit): bool
    {
        $stmt = $this->db->prepare(
            'SELECT failures, updated_at FROM chimeport_mobile_login_attempts WHERE identifier = :id'
        );
        $stmt->execute(['id' => $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $updated = strtotime((string) $row['updated_at'] . ' UTC');
        return $updated !== false && $updated > time() - 900 && (int) $row['failures'] >= $limit;
    }

    private function recordFailure(string $identifier): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO chimeport_mobile_login_attempts(identifier, failures, updated_at)
             VALUES (:id, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 failures = IF(updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE, 1, failures + 1),
                 updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute(['id' => $identifier]);
    }

    private function isJson(ServerRequestInterface $request): bool
    {
        return str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json');
    }

    /** @param array<string,mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $code = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($code)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
