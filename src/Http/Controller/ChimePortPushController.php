<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Infrastructure\ChimePortPushBridge;
use Tms\Security\SessionManager;
use Throwable;

final class ChimePortPushController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ChimePortPushBridge $bridge,
    ) {
    }

    public function config(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return $this->json($response, ['public_key' => $this->bridge->publicKey()]);
        } catch (Throwable) {
            return $this->json($response, ['error' => 'Push configuration unavailable.'], 502);
        }
    }

    public function subscribe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !$this->validSubscription($body)) {
            return $this->json($response, ['error' => 'Invalid push subscription.'], 400);
        }
        try {
            $this->bridge->subscribe($this->userId(), [
                'endpoint' => $body['endpoint'],
                'keys' => [
                    'p256dh' => $body['keys']['p256dh'],
                    'auth' => $body['keys']['auth'],
                ],
            ]);
            return $this->json($response, ['subscribed' => true]);
        } catch (Throwable) {
            return $this->json($response, ['error' => 'Could not enable push notifications.'], 502);
        }
    }

    public function unsubscribe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $endpoint = is_array($body) && isset($body['endpoint']) && is_string($body['endpoint'])
            ? $body['endpoint'] : '';
        if ($endpoint === '' || strlen($endpoint) > 2048) {
            return $this->json($response, ['error' => 'Invalid push endpoint.'], 400);
        }
        try {
            $this->bridge->unsubscribe($this->userId(), $endpoint);
            return $this->json($response, ['subscribed' => false]);
        } catch (Throwable) {
            return $this->json($response, ['error' => 'Could not disable push notifications.'], 502);
        }
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    /** @param array<string,mixed> $body */
    private function validSubscription(array $body): bool
    {
        if (!isset($body['endpoint'], $body['keys']) || !is_string($body['endpoint'])
            || strlen($body['endpoint']) > 2048 || !is_array($body['keys'])) {
            return false;
        }
        foreach (['p256dh', 'auth'] as $key) {
            if (!isset($body['keys'][$key]) || !is_string($body['keys'][$key])
                || strlen($body['keys'][$key]) > 256 || $body['keys'][$key] === '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $body */
    private function json(ResponseInterface $response, array $body, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($body, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
