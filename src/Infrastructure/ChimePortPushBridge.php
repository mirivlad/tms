<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

/**
 * TMS backend only. Converts an authenticated TMS user into a short-lived
 * scoped ChimePort session; browser never sees the trusted application token.
 */
final class ChimePortPushBridge
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $chimePortUrl,
        private readonly string $applicationToken,
    ) {
        $url = parse_url($chimePortUrl);
        if (!is_array($url) || ($url['scheme'] ?? null) !== 'https' ||
            empty($url['host']) || (isset($url['user']) || isset($url['pass'])) ||
            isset($url['query']) || isset($url['fragment']) ||
            ($url['path'] ?? '') !== '') {
            throw new \InvalidArgumentException('ChimePort URL must be an HTTPS origin.');
        }
        if (!str_starts_with($applicationToken, 'cp_a_')) {
            throw new \InvalidArgumentException('ChimePort trusted application token required.');
        }
    }

    public function publicKey(): string
    {
        $response = $this->call('GET', '/api/v1/external/webpush/vapid-public-key', $this->applicationToken);
        $key = $response['public_key'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('ChimePort did not return a VAPID public key.');
        }
        return $key;
    }

    /** @param array{endpoint:string,keys:array{p256dh:string,auth:string}} $subscription */
    public function subscribe(int $userId, string $deviceId, array $subscription): void
    {
        $token = $this->deviceToken($userId,$deviceId);
        $this->call('POST', '/api/v1/external/webpush/subscriptions', $token, $subscription);
    }

    public function unsubscribe(int $userId, string $deviceId, string $endpoint): void
    {
        $token = $this->deviceToken($userId,$deviceId);
        $this->call('DELETE', '/api/v1/external/webpush/subscriptions', $token, ['endpoint' => $endpoint]);
    }

    /**
     * Delegated device login: TMS authenticated the user, and the client
     * supplied a PKCE challenge. Only the client knows the verifier.
     */
    public function issueDeviceTicket(int $userId, string $deviceId, string $challenge): string
    {
        if ($userId <= 0 || preg_match('/^[a-f0-9]{32}$/D', $deviceId) !== 1
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1) {
            throw new \InvalidArgumentException('Invalid user or PKCE challenge.');
        }
        $data = $this->call('POST', '/api/v1/external/tickets', $this->applicationToken, [
            'recipient' => (string) $userId,
            'device_id' => $deviceId,
            'code_challenge' => $challenge,
        ]);
        $ticket = $data['ticket'] ?? null;
        if (!is_string($ticket) || !str_starts_with($ticket, 'cp_k_')) {
            throw new RuntimeException('ChimePort did not return a device ticket.');
        }
        return $ticket;
    }

    /** Server-side cleanup only; authorization is denied by TMS even if unreachable. */
    public function revokeDevice(int $userId, string $deviceId): void
    {
        $this->call('POST', '/api/v1/external/devices/revoke', $this->applicationToken, [
            'recipient' => (string) $userId, 'device_id' => $deviceId,
        ]);
    }

    public function revokeRecipient(int $userId): void
    {
        $this->call('POST', '/api/v1/external/recipients/revoke', $this->applicationToken, [
            'recipient' => (string) $userId,
        ]);
    }

    private function deviceToken(int $userId,string $deviceId): string
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Authenticated TMS user ID required.');
        }

        // Never derive recipient identity from mutable login/password.
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $ticket = $this->issueDeviceTicket($userId, $deviceId, $challenge);

        $session = $this->call('POST', '/api/v1/external/exchange', null, [
            'ticket' => $ticket,
            'code_verifier' => $verifier,
        ]);
        $accessToken = $session['access_token'] ?? null;
        if (!is_string($accessToken) || !str_starts_with($accessToken, 'cp_e_')) {
            throw new RuntimeException('ChimePort did not issue a scoped device session.');
        }
        return $accessToken;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?string $token, ?array $payload = null): array
    {
        $options = [
            'http_errors' => false,
            'allow_redirects' => false,
            'connect_timeout' => 4,
            'timeout' => 8,
            'headers' => ['Accept' => 'application/json'],
        ];
        if ($token !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $token;
        }
        if ($payload !== null) {
            $options['json'] = $payload;
        }
        try {
            $response = $this->http->request($method, $this->chimePortUrl . $path, $options);
        } catch (Throwable) {
            // Never include exception messages: some HTTP libraries expose Authorization.
            throw new RuntimeException('ChimePort connection failed.');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('ChimePort returned HTTP ' . $response->getStatusCode());
        }
        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException('ChimePort returned invalid JSON.');
        }
        return $data;
    }
}
