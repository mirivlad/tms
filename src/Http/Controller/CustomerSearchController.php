<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Security\SessionManager;

final class CustomerSearchController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $needle = is_string($query['q'] ?? null) ? trim((string) $query['q']) : '';
        if (mb_strlen($needle) < 2) {
            return $this->json($response, []);
        }

        $userId = $this->sessions->currentUserId() ?? 0;
        $items = [];
        foreach ($this->customers->searchForUser($userId, $needle, 8) as $customer) {
            $items[] = [
                'id' => $customer->id,
                'name' => $customer->name,
            ];
        }

        return $this->json($response, $items);
    }

    /** @param array<int, array{id:int,name:string}> $payload */
    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
