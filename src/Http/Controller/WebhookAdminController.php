<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Event\DomainEventCatalog;
use Tms\Domain\Webhook\WebhookDeliveryRepository;
use Tms\Domain\Webhook\WebhookSubscriptionRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class WebhookAdminController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly WebhookSubscriptionRepository $subscriptions,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $created = $this->subscriptions->create(
                createdBy: $this->sessions->currentUserId() ?? 0,
                name: (string) ($body['name'] ?? ''),
                endpointUrl: (string) ($body['endpoint_url'] ?? ''),
                eventTypes: $this->eventTypes($body),
                isActive: $this->checked($body, 'is_active'),
            );
        } catch (DomainException $error) {
            return $this->render($request, $response, $error->getMessage(), 422);
        }

        $_SESSION['_webhook_secret_once'] = [
            'id' => $created['id'],
            'secret' => $created['secret'],
        ];
        $_SESSION['_webhook_flash'] = $this->translator->trans('webhooks.created');
        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $this->id($args);
        $before = $this->subscriptions->find($id);
        $body = $this->body($request);
        try {
            $updated = $this->subscriptions->update(
                id: $id,
                name: (string) ($body['name'] ?? ''),
                endpointUrl: (string) ($body['endpoint_url'] ?? ''),
                eventTypes: $this->eventTypes($body),
                isActive: $this->checked($body, 'is_active'),
            );
        } catch (DomainException $error) {
            return $this->render($request, $response, $error->getMessage(), 422);
        }
        if (!$updated) {
            return $this->render($request, $response, $this->translator->trans('webhooks.not_found'), 404);
        }

        $after = $this->subscriptions->find($id);
        if ($before !== null && $after !== null
            && ($before->endpointUrl !== $after->endpointUrl
                || $before->eventTypes !== $after->eventTypes
                || ($before->isActive && !$after->isActive))) {
            $this->deliveries->discardQueuedForSubscription($id);
        }
        $_SESSION['_webhook_flash'] = $this->translator->trans('webhooks.saved');
        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function rotateSecret(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $this->id($args);
        $secret = $this->subscriptions->rotateSecret($id);
        if ($secret === null) {
            return $this->render($request, $response, $this->translator->trans('webhooks.not_found'), 404);
        }
        $_SESSION['_webhook_secret_once'] = ['id' => $id, 'secret' => $secret];
        $_SESSION['_webhook_flash'] = $this->translator->trans('webhooks.secret_rotated');
        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($this->subscriptions->delete($this->id($args))) {
            $_SESSION['_webhook_flash'] = $this->translator->trans('webhooks.deleted');
        }
        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function retryDelivery(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $this->id($args);
        $_SESSION['_webhook_flash'] = $this->deliveries->retry($id)
            ? $this->translator->trans('webhooks.delivery_requeued')
            : $this->translator->trans('webhooks.delivery_retry_unavailable');
        return $this->redirect($response);
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $error = null,
        int $status = 200,
    ): ResponseInterface {
        $flash = $_SESSION['_webhook_flash'] ?? null;
        $secret = $_SESSION['_webhook_secret_once'] ?? null;
        unset($_SESSION['_webhook_flash'], $_SESSION['_webhook_secret_once']);

        return $this->view->render($response, 'admin/webhooks.twig', [
            'subscriptions' => $this->subscriptions->listAll(),
            'deliveries' => $this->deliveries->recent(),
            'event_types' => DomainEventCatalog::all(),
            'secret_once' => is_array($secret) ? $secret : null,
            'notice' => is_string($flash) ? $flash : null,
            'error' => $error,
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername(),
            'role' => $this->sessions->currentRole(),
        ])->withStatus($status);
    }

    /** @param array<string, mixed> $body
     * @return list<string>
     */
    private function eventTypes(array $body): array
    {
        $raw = $body['event_types'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $types = [];
        foreach ($raw as $value) {
            if (is_string($value)) {
                $types[] = $value;
            }
        }
        return $types;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $body */
    private function checked(array $body, string $key): bool
    {
        return isset($body[$key]) && in_array((string) $body[$key], ['1', 'on', 'true'], true);
    }

    /** @param array<string, string> $args */
    private function id(array $args): int
    {
        $raw = $args['id'] ?? '';
        return ctype_digit($raw) ? (int) $raw : 0;
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }

    private function redirect(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/admin/webhooks')->withStatus(302);
    }
}
