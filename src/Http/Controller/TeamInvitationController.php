<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Team\TeamInvitationRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class TeamInvitationController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly TeamInvitationRepository $invitations,
        private readonly Translator $translator,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $notice = $_SESSION['_team_invitation_notice'] ?? null;
        unset($_SESSION['_team_invitation_notice']);

        return $this->view->render($response, 'teams/invitations.twig', [
            'csrf_token' => $this->csrfToken($request),
            'username' => $this->sessions->currentUsername() ?? '',
            'invitations' => $this->invitations->listPendingForUser($this->userId()),
            'notice' => is_string($notice) ? $notice : null,
        ]);
    }

    /** @param array<string, string> $args */
    public function accept(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $ok = $this->invitations->accept($this->userId(), $this->id($args));
        $_SESSION['_team_invitation_notice'] = $this->translator->trans(
            $ok ? 'teams.invite_accepted' : 'teams.invite_unavailable'
        );
        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    public function decline(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $ok = $this->invitations->decline($this->userId(), $this->id($args));
        $_SESSION['_team_invitation_notice'] = $this->translator->trans(
            $ok ? 'teams.invite_declined' : 'teams.invite_unavailable'
        );
        return $this->redirect($response);
    }

    /** @param array<string, string> $args */
    private function id(array $args): int
    {
        $value = $args['id'] ?? '';
        return ctype_digit($value) ? (int) $value : 0;
    }

    private function redirect(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/invitations')->withStatus(302);
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
