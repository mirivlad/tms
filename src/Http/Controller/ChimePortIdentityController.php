<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Notification\ChimePortDeviceRepository;
use Tms\Infrastructure\ChimePortPushBridge;
use Tms\Security\SessionManager;
use Throwable;

/**
 * TMS is authoritative for device permissions. ChimePort introspects on each
 * external access, refresh and push. No caching of positive decisions.
 */
final class ChimePortIdentityController
{
    public function __construct(
        private readonly ChimePortDeviceRepository $devices,
        private readonly SessionManager $sessions,
        private readonly string $applicationToken,
        private readonly ChimePortPushBridge $bridge,
    ) {
    }

    public function introspect(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $provided = $request->getHeaderLine('Authorization');
        if (!hash_equals('Bearer ' . $this->applicationToken, $provided)) {
            return $this->json($response, ['active' => false], 401);
        }
        $body = $request->getParsedBody();
        $recipient = is_array($body) ? $body['recipient'] ?? null : null;
        $id = is_array($body) ? $body['device_id'] ?? null : null;
        if (!is_string($recipient) || !ctype_digit($recipient)
            || strlen($recipient) > 20 || (int) $recipient <= 0
            || !is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            return $this->json($response, ['active' => false], 400);
        }
        try {
            return $this->json($response, ['active' => $this->devices->active((int) $recipient, $id)]);
        } catch (Throwable) {
            return $this->json($response, ['error' => 'Identity verification unavailable.'], 503);
        }
    }

    /** @param array<string,string> $args */
    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $deviceId = is_string($args['device'] ?? null) ? $args['device'] : '';
        if (!$this->devices->revoke($userId, $deviceId)) {
            return $response->withHeader('Location', '/settings/notifications')->withStatus(302);
        }
        // Revocation in TMS is immediate, independently of ChimePort's network state.
        // This best-effort cleanup removes remote refresh tokens and webpush endpoints.
        try {
            $this->bridge->revokeDevice($userId, $deviceId);
        } catch (Throwable) {
            error_log('ChimePort device cleanup delayed; TMS introspection denies the revoked device.');
        }
        return $response->withHeader('Location', '/settings/notifications')->withStatus(302);
    }

    public function revokeAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->sessions->currentUserId() ?? 0;
        $this->devices->revokeAll($userId);
        try {
            $this->bridge->revokeRecipient($userId);
        } catch (Throwable) {
            error_log('ChimePort recipient cleanup delayed; TMS introspection denies revoked devices.');
        }
        return $response->withHeader('Location', '/settings/notifications')->withStatus(302);
    }

    /** @param array<string,mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $code = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($code)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
