<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Project\ProjectRepository;
use Tms\Domain\Team\TeamInvitationRepository;
use Tms\Domain\Team\TeamRepository;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class TeamController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly TeamRepository $teams,
        private readonly ProjectRepository $projects,
        private readonly TeamInvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'teams/index.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'teams' => $this->teams->listForUser($this->userId()),
            'notice' => $this->consumeNotice(),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $teamId = $this->teams->createForUser(
                $this->userId(),
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
            );
        } catch (DomainException $error) {
            $this->notice('error', $this->teamErrorKey($error));
            return $this->redirect($response, '/teams');
        }

        $this->notice('success', 'teams.created');
        return $this->redirect($response, '/teams/' . $teamId);
    }

    /** @param array<string, string> $args */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $teamId = $this->id($args, 'id');
        $team = $this->teams->findForMember($this->userId(), $teamId);
        if ($team === null) {
            return $this->notFound($response);
        }

        return $this->view->render($response, 'teams/show.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'team' => $team,
            'is_lead' => $team->currentUserIsLead(),
            'members' => $this->teams->listMembers($this->userId(), $teamId),
            'projects' => $this->projects->listForTeamForUser($this->userId(), $teamId),
            'roles' => TeamRepository::ROLES,
            'invitations' => $team->currentUserIsLead()
                ? $this->invitations->listForTeam($this->userId(), $teamId)
                : [],
            'notice' => $this->consumeNotice(),
        ]);
    }

    /** @param array<string, string> $args */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $teamId = $this->id($args, 'id');
        $body = $this->body($request);
        try {
            if (!$this->teams->updateForLead(
                $this->userId(),
                $teamId,
                (string) ($body['name'] ?? ''),
                (string) ($body['description'] ?? ''),
            )) {
                return $this->notFound($response);
            }
        } catch (DomainException $error) {
            $this->notice('error', $this->teamErrorKey($error));
            return $this->redirect($response, '/teams/' . $teamId);
        }

        $this->notice('success', 'teams.saved');
        return $this->redirect($response, '/teams/' . $teamId);
    }

    /** @param array<string, string> $args */
    public function invite(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $teamId = $this->id($args, 'id');
        if ($this->teams->findForLead($this->userId(), $teamId) === null) {
            return $this->notFound($response);
        }

        $body = $this->body($request);
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $user = $this->users->findByIdentifier($identifier);
        if ($user === null || !$user->isActive || !$user->isApproved) {
            $this->notice('error', 'teams.invite_user_unavailable');
            return $this->redirect($response, '/teams/' . $teamId);
        }

        try {
            $this->invitations->invite($teamId, $user->id, $this->userId());
        } catch (DomainException $error) {
            $key = match ($error->getMessage()) {
                'User is already a team member.' => 'teams.invite_already_member',
                'A pending invitation already exists.' => 'teams.invite_already_pending',
                default => 'teams.invite_failed',
            };
            $this->notice('error', $key);
            return $this->redirect($response, '/teams/' . $teamId);
        }

        $this->notice('success', 'teams.invite_created');
        return $this->redirect($response, '/teams/' . $teamId);
    }

    /** @param array<string, string> $args */
    public function revokeInvitation(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $teamId = $this->id($args, 'id');
        $invitationId = $this->id($args, 'invitationId');
        if (!$this->invitations->revokeForLead($this->userId(), $teamId, $invitationId)) {
            return $this->notFound($response);
        }
        $this->notice('success', 'teams.invite_revoked');
        return $this->redirect($response, '/teams/' . $teamId);
    }

    /** @param array<string, string> $args */
    public function changeRole(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $teamId = $this->id($args, 'id');
        $targetUserId = $this->id($args, 'userId');
        $body = $this->body($request);
        try {
            if (!$this->teams->changeRoleForLead(
                $this->userId(),
                $teamId,
                $targetUserId,
                (string) ($body['role'] ?? ''),
            )) {
                return $this->notFound($response);
            }
        } catch (DomainException $error) {
            $this->notice('error', $this->teamErrorKey($error));
            return $this->redirect($response, '/teams/' . $teamId);
        }

        $this->notice('success', 'teams.role_saved');
        return $this->redirect($response, '/teams/' . $teamId);
    }

    /** @param array<string, string> $args */
    public function removeMember(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $teamId = $this->id($args, 'id');
        $targetUserId = $this->id($args, 'userId');
        try {
            if (!$this->teams->removeMemberForLead($this->userId(), $teamId, $targetUserId)) {
                return $this->notFound($response);
            }
        } catch (DomainException $error) {
            $this->notice('error', $this->teamErrorKey($error));
            return $this->redirect($response, '/teams/' . $teamId);
        }

        $this->notice('success', 'teams.member_removed');
        return $this->redirect($response, '/teams/' . $teamId);
    }

    /** @param array<string, string> $args */
    public function leave(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $teamId = $this->id($args, 'id');
        try {
            if (!$this->teams->leave($this->userId(), $teamId)) {
                return $this->notFound($response);
            }
        } catch (DomainException $error) {
            $this->notice('error', $this->teamErrorKey($error));
            return $this->redirect($response, '/teams/' . $teamId);
        }

        $this->notice('success', 'teams.left');
        return $this->redirect($response, '/teams');
    }

    /** @param array<string, string> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $teamId = $this->id($args, 'id');
        try {
            if (!$this->teams->deleteForLead($this->userId(), $teamId)) {
                return $this->notFound($response);
            }
        } catch (DomainException $error) {
            $this->notice('error', $this->teamErrorKey($error));
            return $this->redirect($response, '/teams/' . $teamId);
        }
        $this->notice('success', 'teams.deleted');
        return $this->redirect($response, '/teams');
    }

    private function teamErrorKey(DomainException $error): string
    {
        return match ($error->getMessage()) {
            'Team name must contain 1-160 characters.' => 'teams.validation_name',
            'Team description cannot exceed 20000 characters.' => 'teams.validation_description',
            'Unsupported team role.' => 'teams.validation_role',
            'A team must have at least one lead.' => 'teams.last_lead',
            'A team with projects cannot be deleted.' => 'teams.delete_with_projects',
            default => 'teams.operation_failed',
        };
    }

    private function notice(string $kind, string $key): void
    {
        $_SESSION['_team_notice'] = [
            'kind' => $kind,
            'message' => $this->translator->trans($key),
        ];
    }

    /** @return array{kind:string,message:string}|null */
    private function consumeNotice(): ?array
    {
        $notice = $_SESSION['_team_notice'] ?? null;
        unset($_SESSION['_team_notice']);
        if (!is_array($notice)
            || !is_string($notice['kind'] ?? null)
            || !is_string($notice['message'] ?? null)) {
            return null;
        }
        return ['kind' => $notice['kind'], 'message' => $notice['message']];
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->translator->trans('teams.not_found'));
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withStatus(404);
    }

    private function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    /** @param array<string, string> $args */
    private function id(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
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
