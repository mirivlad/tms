<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\Domain\SavedView\SavedViewQuery;
use Tms\Domain\SavedView\SavedViewRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class SavedViewController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly SavedViewRepository $views,
        private readonly SavedViewQuery $query,
        private readonly Translator $translator,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        $rawQuery = [];
        $state = is_string($body['query'] ?? null) ? (string) $body['query'] : '';
        parse_str($state, $rawQuery);

        try {
            $id = $this->views->create(
                $this->userId(),
                is_string($body['name'] ?? null) ? (string) $body['name'] : '',
                $this->query->normalize($rawQuery),
                ($body['is_default'] ?? null) === '1',
            );
            $this->notice('success', 'saved_views.created');
            return $response->withHeader('Location', '/tasks/views/' . $id)->withStatus(302);
        } catch (DomainException $error) {
            $this->noticeRaw('error', $this->domainMessage($error));
        } catch (PDOException) {
            $this->notice('error', 'saved_views.name_exists');
        }

        return $response->withHeader('Location', '/tasks')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function apply(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $view = $this->views->findForUser($this->userId(), $this->id($args));
        if ($view === null) {
            return $this->notFound($response);
        }

        $query = $this->query->normalize($view->query);
        $query['view'] = $view->id;
        return $response
            ->withHeader('Location', '/tasks?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            ->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function setDefault(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $viewId = $this->id($args);
        if (!$this->views->setDefault($this->userId(), $viewId)) {
            return $this->notFound($response);
        }
        $this->notice('success', 'saved_views.default_set');
        return $response->withHeader('Location', '/tasks/views/' . $viewId)->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->views->delete($this->userId(), $this->id($args))) {
            return $this->notFound($response);
        }
        $this->notice('success', 'saved_views.deleted');
        return $response->withHeader('Location', '/tasks')->withStatus(302);
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    /** @param array<string, string> $args */
    private function id(array $args): int
    {
        $value = $args['id'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('saved_views.not_found'));
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function notice(string $kind, string $key): void
    {
        $this->noticeRaw($kind, $this->translator->trans($key));
    }

    private function noticeRaw(string $kind, string $message): void
    {
        $_SESSION['_saved_view_notice'] = ['kind' => $kind, 'message' => $message];
    }

    private function domainMessage(DomainException $error): string
    {
        return $error->getMessage() === 'Saved view name must contain 1-96 characters.'
            ? $this->translator->trans('saved_views.validation_name')
            : $this->translator->trans('saved_views.invalid');
    }
}
