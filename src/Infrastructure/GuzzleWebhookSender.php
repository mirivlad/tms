<?php

declare(strict_types=1);

namespace Tms\Infrastructure;

use GuzzleHttp\ClientInterface;
use Throwable;

final class GuzzleWebhookSender implements WebhookSender
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly float $timeoutSeconds = 10.0,
    ) {
    }

    public function send(string $url, array $headers, string $body): WebhookDeliveryResult
    {
        try {
            $response = $this->client->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'http_errors' => false,
                'allow_redirects' => false,
                'connect_timeout' => $this->timeoutSeconds,
                'timeout' => $this->timeoutSeconds,
            ]);
            $status = $response->getStatusCode();
            return new WebhookDeliveryResult(
                success: $status >= 200 && $status < 300,
                httpStatus: $status,
                error: $status >= 200 && $status < 300 ? null : 'HTTP ' . $status,
            );
        } catch (Throwable $error) {
            return new WebhookDeliveryResult(
                success: false,
                httpStatus: null,
                error: $error->getMessage(),
            );
        }
    }
}
