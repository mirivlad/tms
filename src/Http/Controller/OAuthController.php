<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tms\Domain\Mcp\OAuthError;
use Tms\Domain\Mcp\OAuthServer;
use Tms\Security\PasswordAuthenticator;
use Tms\Security\SessionManager;

final class OAuthController
{
    public function __construct(private readonly OAuthServer $server,private readonly Twig $view,
        private readonly PasswordAuthenticator $authenticator,private readonly SessionManager $sessions) {}

    public function metadata(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        return $this->json($response,$this->server->metadata());
    }

    public function resourceMetadata(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        return $this->json($response,$this->server->resourceMetadata());
    }

    public function register(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        return $this->api($response,function() use($request): array {
            if (!str_starts_with(strtolower($request->getHeaderLine('Content-Type')),'application/json')) {
                throw new OAuthError('invalid_request','JSON required.');
            }
            $raw=(string)$request->getBody();
            if (strlen($raw)>32768) { throw new OAuthError('invalid_request','Registration request too large.'); }
            try { $args=json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new OAuthError('invalid_request','Invalid JSON.'); }
            if (!is_array($args)||array_is_list($args)) { throw new OAuthError('invalid_request','Invalid registration request.'); }
            return $this->server->register($args);
        },201);
    }

    public function token(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        return $this->api($response,fn()=>$this->server->token($this->form($request),$request->getHeaderLine('Authorization')));
    }

    public function revoke(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        try {
            $this->server->revoke($this->form($request),$request->getHeaderLine('Authorization'));
            return $this->headers($response)->withStatus(200);
        } catch (OAuthError $e) { return $this->error($response,$e); }
    }

    public function authorize(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        try {
            $params=$this->server->authorizeRequest($this->parseParameters($request->getUri()->getQuery()));
            $pending=$_SESSION['_oauth_pending']??[];
            if (!is_array($pending)) { $pending=[]; }
            $pending=array_filter($pending,static fn($p)=>is_array($p)&&($p['expires']??0)>time());
            $pending=array_slice($pending,-4,null,true);
            $nonce=OAuthServer::random('consent_');
            $pending[$nonce]=['params'=>$params,'expires'=>time()+600,'attempts'=>0];
            $_SESSION['_oauth_pending']=$pending;
            return $this->consent($request,$response,$nonce,$params);
        } catch (OAuthError $e) { return $this->error($response,$e); }
    }

    public function decide(ServerRequestInterface $request,ResponseInterface $response): ResponseInterface
    {
        try {
            $body=$this->form($request);
            $nonce=OAuthServer::str($body,'nonce');
            $pending=$_SESSION['_oauth_pending']??[];
            $entry=is_array($pending)?($pending[$nonce]??null):null;
            if (!is_array($entry)||($entry['expires']??0)<=time()||!is_array($entry['params']??null)) {
                throw new OAuthError('invalid_request','Consent expired; restart authorization.');
            }
            $params=$entry['params'];
            $decision=OAuthServer::str($body,'decision');
            if ($decision==='deny') {
                unset($_SESSION['_oauth_pending'][$nonce]);
                return $this->redirect($response,$params,['error'=>'access_denied']);
            }
            if ($decision!=='allow') { throw new OAuthError('invalid_request','Explicit consent required.'); }
            if ($this->sessions->isImpersonating()) { throw new OAuthError('access_denied','Exit impersonation before linking an account.'); }
            if ($entry['attempts']>=5) {
                unset($_SESSION['_oauth_pending'][$nonce]);
                throw new OAuthError('access_denied','Too many attempts; restart authorization.',429);
            }
            $_SESSION['_oauth_pending'][$nonce]['attempts']++;
            $username=trim(OAuthServer::str($body,'username'));
            $password=OAuthServer::str($body,'password');
            $user=$this->authenticator->authenticate($username,$password);
            if ($user===null) {
                return $this->consent($request,$response->withStatus(401),$nonce,$params,true);
            }
            $code=$this->server->issueCode($user->id,$params);
            unset($_SESSION['_oauth_pending'][$nonce]);
            return $this->redirect($response,$params,['code'=>$code]);
        } catch (OAuthError $e) { return $this->error($response,$e); }
    }

    /** @param array<string,mixed> $params */
    private function consent(ServerRequestInterface $request,ResponseInterface $response,string $nonce,array $params,bool $error=false): ResponseInterface
    {
        return $this->view->render($this->headers($response)
            ->withHeader('Content-Security-Policy',"default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
            ->withHeader('X-Frame-Options','DENY'),
            'auth/oauth_consent.twig',['csrf_token'=>$request->getAttribute('csrf_token'),'nonce'=>$nonce,
                'client_name'=>$params['client_name'],'redirect_uri'=>$params['redirect_uri'],'scope'=>$params['scope'],
                'can_write'=>str_contains((string)$params['scope'],'tms:write'),'can_delete'=>str_contains((string)$params['scope'],'tms:delete'),
                'username'=>$this->sessions->currentUsername()??'','error'=>$error]);
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,string> $result
     */
    private function redirect(ResponseInterface $response,array $params,array $result): ResponseInterface
    {
        $uri=(string)$params['redirect_uri'];
        $result['iss']=$this->server->issuer;
        if ($params['state']!=='') { $result['state']=(string)$params['state']; }
        return $this->headers($response)->withStatus(303)->withHeader('Location',$uri.(str_contains($uri,'?')?'&':'?').http_build_query($result,'','&',PHP_QUERY_RFC3986));
    }

    /** @return array<string,mixed> */
    private function form(ServerRequestInterface $request): array
    {
        if (!str_starts_with(strtolower($request->getHeaderLine('Content-Type')),'application/x-www-form-urlencoded')) {
            throw new OAuthError('invalid_request','Form encoding required.');
        }
        return $this->parseParameters((string)$request->getBody());
    }

    /** @return array<string,string> */
    private function parseParameters(string $raw): array
    {
        if (strlen($raw)>32768) { throw new OAuthError('invalid_request','Request too large.'); }
        $result=[];
        if ($raw==='') { return $result; }
        foreach (explode('&',$raw) as $pair) {
            if ($pair==='') { continue; }
            $parts=explode('=',$pair,2);
            $key=urldecode($parts[0]);
            $value=urldecode($parts[1]??'');
            if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$key)||array_key_exists($key,$result)||count($result)>=40) {
                throw new OAuthError('invalid_request','Invalid or repeated parameter.');
            }
            $result[$key]=$value;
        }
        return $result;
    }

    /** @param callable():array<string,mixed> $operation */
    private function api(ResponseInterface $response,callable $operation,int $status=200): ResponseInterface
    {
        try { return $this->json($response,$operation(),$status); }
        catch (OAuthError $e) { return $this->error($response,$e); }
        catch (\Throwable $e) {
            error_log('TMS OAuth operation failed: '.get_class($e));
            return $this->json($response,['error'=>'server_error'],500);
        }
    }

    private function error(ResponseInterface $response,OAuthError $e): ResponseInterface
    {
        if ($e->error==='invalid_client'&&$e->status===401) {
            $response=$response->withHeader('WWW-Authenticate','Basic realm="TMS OAuth"');
        }
        return $this->json($response,['error'=>$e->error,'error_description'=>$e->getMessage()],$e->status);
    }

    private function headers(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control','no-store')->withHeader('Pragma','no-cache')
            ->withHeader('Referrer-Policy','no-referrer')->withHeader('X-Content-Type-Options','nosniff');
    }

    /** @param array<string,mixed> $data */
    private function json(ResponseInterface $response,array $data,int $status=200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return $this->headers($response)->withStatus($status)->withHeader('Content-Type','application/json');
    }
}
