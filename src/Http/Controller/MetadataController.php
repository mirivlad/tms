<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Customer\CustomerRepository;
use Tms\Domain\Status\StatusRecord;
use Tms\Domain\Status\StatusRepository;
use Tms\Domain\TaskType\TaskTypeRecord;
use Tms\Domain\TaskType\TaskTypeRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class MetadataController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly StatusRepository $statuses,
        private readonly TaskTypeRepository $taskTypes,
        private readonly CustomerRepository $customers,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response);
    }

    public function createStatus(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->body($request);
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $color = trim((string) ($data['color'] ?? '#6b7280'));

        if (!$this->validShortName($name)) {
            return $this->error($request, $response, 'validation.metadata_name_required', 'statuses');
        }
        if (mb_strlen($description) > 512) {
            return $this->error($request, $response, 'validation.metadata_description_too_long', 'statuses');
        }
        if (!$this->validColor($color)) {
            return $this->error($request, $response, 'validation.status_color_invalid', 'statuses');
        }

        try {
            $this->statuses->createForUser(
                $this->userId(),
                $name,
                $description,
                $color,
                $this->checked($data, 'is_default'),
                $this->checked($data, 'is_completion'),
                $this->checked($data, 'show_on_board'),
            );
        } catch (PDOException) {
            return $this->error($request, $response, 'validation.metadata_duplicate_name', 'statuses');
        }

        return $this->redirect($response, 'statuses');
    }

    /** @param array<string, string> $args */
    public function updateStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $statusId = $this->routeId($args);
        if ($this->statuses->findForUser($this->userId(), $statusId) === null) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'statuses', 404);
        }

        $data = $this->body($request);
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $color = trim((string) ($data['color'] ?? '#6b7280'));

        if (!$this->validShortName($name)) {
            return $this->error($request, $response, 'validation.metadata_name_required', 'statuses');
        }
        if (mb_strlen($description) > 512) {
            return $this->error($request, $response, 'validation.metadata_description_too_long', 'statuses');
        }
        if (!$this->validColor($color)) {
            return $this->error($request, $response, 'validation.status_color_invalid', 'statuses');
        }

        try {
            $this->statuses->updateForUser(
                $this->userId(),
                $statusId,
                $name,
                $description,
                $color,
                $this->checked($data, 'show_on_board'),
            );
        } catch (PDOException) {
            return $this->error($request, $response, 'validation.metadata_duplicate_name', 'statuses');
        }

        return $this->redirect($response, 'statuses');
    }

    /** @param array<string, string> $args */
    public function setDefaultStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        if (!$this->statuses->setDefaultForUser($this->userId(), $this->routeId($args))) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'statuses', 404);
        }

        return $this->redirect($response, 'statuses');
    }

    /** @param array<string, string> $args */
    public function setCompletionStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        if (!$this->statuses->setCompletionForUser($this->userId(), $this->routeId($args))) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'statuses', 404);
        }

        return $this->redirect($response, 'statuses');
    }

    /** @param array<string, string> $args */
    public function moveStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $data = $this->body($request);
        $direction = (string) ($data['direction'] ?? '');
        if (!in_array($direction, ['up', 'down'], true)) {
            return $this->error($request, $response, 'validation.metadata_move_invalid', 'statuses');
        }

        $records = $this->statuses->listForUser($this->userId());
        if (!$this->moveStatusRecord($records, $this->routeId($args), $direction)) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'statuses', 404);
        }

        return $this->redirect($response, 'statuses');
    }

    /** @param array<string, string> $args */
    public function deleteStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $statusId = $this->routeId($args);
        if ($this->statuses->findForUser($this->userId(), $statusId) === null) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'statuses', 404);
        }
        if (!$this->statuses->deleteForUser($this->userId(), $statusId)) {
            return $this->error($request, $response, 'validation.status_delete_blocked', 'statuses', 409);
        }

        return $this->redirect($response, 'statuses');
    }

    public function createType(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->body($request);
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if (!$this->validShortName($name)) {
            return $this->error($request, $response, 'validation.metadata_name_required', 'types');
        }
        if (mb_strlen($description) > 512) {
            return $this->error($request, $response, 'validation.metadata_description_too_long', 'types');
        }

        try {
            $this->taskTypes->createForUser($this->userId(), $name, $description);
        } catch (PDOException) {
            return $this->error($request, $response, 'validation.metadata_duplicate_name', 'types');
        }

        return $this->redirect($response, 'types');
    }

    /** @param array<string, string> $args */
    public function updateType(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $typeId = $this->routeId($args);
        if ($this->taskTypes->findForUser($this->userId(), $typeId) === null) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'types', 404);
        }

        $data = $this->body($request);
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        if (!$this->validShortName($name)) {
            return $this->error($request, $response, 'validation.metadata_name_required', 'types');
        }
        if (mb_strlen($description) > 512) {
            return $this->error($request, $response, 'validation.metadata_description_too_long', 'types');
        }

        try {
            $this->taskTypes->updateForUser($this->userId(), $typeId, $name, $description);
        } catch (PDOException) {
            return $this->error($request, $response, 'validation.metadata_duplicate_name', 'types');
        }

        return $this->redirect($response, 'types');
    }

    /** @param array<string, string> $args */
    public function moveType(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $data = $this->body($request);
        $direction = (string) ($data['direction'] ?? '');
        if (!in_array($direction, ['up', 'down'], true)) {
            return $this->error($request, $response, 'validation.metadata_move_invalid', 'types');
        }

        $records = $this->taskTypes->listForUser($this->userId());
        if (!$this->moveTypeRecord($records, $this->routeId($args), $direction)) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'types', 404);
        }

        return $this->redirect($response, 'types');
    }

    /** @param array<string, string> $args */
    public function deleteType(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $typeId = $this->routeId($args);
        if ($this->taskTypes->findForUser($this->userId(), $typeId) === null) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'types', 404);
        }
        if (!$this->taskTypes->deleteForUser($this->userId(), $typeId)) {
            return $this->error($request, $response, 'validation.type_delete_blocked', 'types', 409);
        }

        return $this->redirect($response, 'types');
    }

    public function createCustomer(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->body($request);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->error($request, $response, 'validation.metadata_name_required', 'customers');
        }
        if (mb_strlen($name) > 255) {
            return $this->error($request, $response, 'validation.customer_name_too_long', 'customers');
        }

        try {
            $this->customers->createForUser($this->userId(), $name);
        } catch (PDOException) {
            return $this->error($request, $response, 'validation.metadata_duplicate_name', 'customers');
        }

        return $this->redirect($response, 'customers');
    }

    /** @param array<string, string> $args */
    public function updateCustomer(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $customerId = $this->routeId($args);
        if ($this->customers->findForUser($this->userId(), $customerId) === null) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'customers', 404);
        }

        $data = $this->body($request);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->error($request, $response, 'validation.metadata_name_required', 'customers');
        }
        if (mb_strlen($name) > 255) {
            return $this->error($request, $response, 'validation.customer_name_too_long', 'customers');
        }

        try {
            $this->customers->updateForUser($this->userId(), $customerId, $name);
        } catch (PDOException) {
            return $this->error($request, $response, 'validation.metadata_duplicate_name', 'customers');
        }

        return $this->redirect($response, 'customers');
    }

    /** @param array<string, string> $args */
    public function deleteCustomer(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $customerId = $this->routeId($args);
        if ($this->customers->findForUser($this->userId(), $customerId) === null) {
            return $this->error($request, $response, 'validation.metadata_not_found', 'customers', 404);
        }
        if (!$this->customers->deleteForUser($this->userId(), $customerId)) {
            return $this->error($request, $response, 'validation.customer_delete_blocked', 'customers', 409);
        }

        return $this->redirect($response, 'customers');
    }

    /**
     * @param list<StatusRecord> $records
     */
    private function moveStatusRecord(array $records, int $statusId, string $direction): bool
    {
        $ids = array_map(static fn (StatusRecord $record): int => $record->id, $records);
        $index = array_search($statusId, $ids, true);
        if ($index === false) {
            return false;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($ids[$target])) {
            return true;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        return $this->statuses->reorderForUser($this->userId(), $ids);
    }

    /**
     * @param list<TaskTypeRecord> $records
     */
    private function moveTypeRecord(array $records, int $typeId, string $direction): bool
    {
        $ids = array_map(static fn (TaskTypeRecord $record): int => $record->id, $records);
        $index = array_search($typeId, $ids, true);
        if ($index === false) {
            return false;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($ids[$target])) {
            return true;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        return $this->taskTypes->reorderForUser($this->userId(), $ids);
    }

    private function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?string $error = null,
        ?string $section = null,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($response, 'metadata/index.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'statuses' => $this->statuses->listForUser($this->userId()),
            'task_types' => $this->taskTypes->listForUser($this->userId()),
            'customers' => $this->customers->listForUser($this->userId(), 500),
            'error' => $error,
            'error_section' => $section,
        ])->withStatus($status);
    }

    private function error(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $translationKey,
        string $section,
        int $status = 422,
    ): ResponseInterface {
        return $this->render(
            $request,
            $response,
            $this->translator->trans($translationKey),
            $section,
            $status,
        );
    }

    private function redirect(ResponseInterface $response, string $section): ResponseInterface
    {
        return $response->withHeader('Location', '/metadata#' . $section)->withStatus(302);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $data */
    private function checked(array $data, string $key): bool
    {
        return isset($data[$key]) && (string) $data[$key] === '1';
    }

    private function validShortName(string $name): bool
    {
        return $name !== '' && mb_strlen($name) <= 96;
    }

    private function validColor(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/D', $color) === 1;
    }

    /** @param array<string, string> $args */
    private function routeId(array $args): int
    {
        $value = $args['id'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function userId(): int
    {
        return $this->sessions->currentUserId() ?? 0;
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
