<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Mcp\McpTokenRepository;
use Tms\Domain\User\UserRepository;
use Tms\I18n\Translator;
use Tms\Security\SessionManager;

final class McpSettingsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
        private readonly McpTokenRepository $tokens,
        private readonly Translator $translator,
        private readonly string $endpoint,
    ) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request,$response);
    }

    public function issue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uid=$this->sessions->currentUserId()??0;
        $user=$this->users->findById($uid);
        $body=$request->getParsedBody();
        $body=is_array($body)?$body:[];
        $password=$body['password']??null;
        if ($user===null || !is_string($password) || !password_verify($password,$user->passwordHash)) {
            return $this->render($request,$response->withStatus(422),error:$this->translator->trans('mcp.bad_password'));
        }
        $label=$body['label']??null;
        $scope=$body['scope']??null;
        $days=$body['days']??null;
        if (!is_string($label)||!in_array($scope,['read','write','delete'],true)||!is_string($days)||!ctype_digit($days)) {
            return $this->render($request,$response->withStatus(422),error:$this->translator->trans('mcp.invalid'));
        }
        try {
            $secret=$this->tokens->issue($uid,trim($label),$scope!=='read',(int)$days,$scope==='delete');
        } catch (\InvalidArgumentException) {
            return $this->render($request,$response->withStatus(422),error:$this->translator->trans('mcp.invalid'));
        }
        return $this->render($request,$response,secret:$secret);
    }

    /** @param array<string,string> $args */
    public function revoke(ServerRequestInterface $request, ResponseInterface $response,array $args): ResponseInterface
    {
        $this->tokens->revoke($this->sessions->currentUserId()??0,(int)($args['id']??0));
        return $response->withHeader('Location','/settings/mcp')->withStatus(303)->withHeader('Cache-Control','no-store');
    }

    private function render(ServerRequestInterface $request,ResponseInterface $response,?string $secret=null,?string $error=null): ResponseInterface
    {
        return $this->view->render($response->withHeader('Cache-Control','no-store')->withHeader('Referrer-Policy','no-referrer'),
            'settings/mcp.twig',[
                'csrf_token'=>$request->getAttribute('csrf_token'),
                'username'=>$this->sessions->currentUsername(),
                'tokens'=>$this->tokens->listForUser($this->sessions->currentUserId()??0),
                'endpoint'=>$this->endpoint,'secret'=>$secret,'error'=>$error,
            ]);
    }
}
