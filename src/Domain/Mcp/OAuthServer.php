<?php

declare(strict_types=1);

namespace Tms\Domain\Mcp;

use PDO;

final class OAuthServer
{
    public const SCOPES = ['tms:read','tms:write','tms:delete'];
    private const ACCESS_SECONDS = 3600;
    public readonly string $issuer;
    public readonly string $resource;

    public function __construct(private readonly PDO $db,string $appUrl)
    {
        $this->issuer=rtrim($appUrl,'/');
        $this->resource=$this->issuer.'/mcp';
    }

    public function enabled(): bool
    {
        $url=parse_url($this->issuer);
        return is_array($url)&&isset($url['scheme'],$url['host'])
            && !isset($url['user'])&&!isset($url['pass'])&&!isset($url['query'])&&!isset($url['fragment'])
            && (!isset($url['path'])||$url['path']==='')
            && ($url['scheme']==='https'||($url['scheme']==='http'&&in_array($url['host'],['127.0.0.1','[::1]'],true)));
    }

    /** @return array<string,mixed> */
    public function resourceMetadata(): array
    {
        return ['resource'=>$this->resource,'authorization_servers'=>[$this->issuer],
            'scopes_supported'=>self::SCOPES,'bearer_methods_supported'=>['header'],'resource_name'=>'TMS'];
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return ['issuer'=>$this->issuer,'authorization_endpoint'=>$this->issuer.'/oauth/authorize',
            'token_endpoint'=>$this->issuer.'/oauth/token','registration_endpoint'=>$this->issuer.'/oauth/register',
            'revocation_endpoint'=>$this->issuer.'/oauth/revoke','scopes_supported'=>self::SCOPES,
            'response_types_supported'=>['code'],'response_modes_supported'=>['query'],
            'grant_types_supported'=>['authorization_code','refresh_token'],
            'token_endpoint_auth_methods_supported'=>['none','client_secret_basic','client_secret_post'],
            'revocation_endpoint_auth_methods_supported'=>['none','client_secret_basic','client_secret_post'],
            'code_challenge_methods_supported'=>['S256'],'authorization_response_iss_parameter_supported'=>true,
            'client_id_metadata_document_supported'=>false];
    }

    public function challenge(string $error='',string $scope='tms:read'): string
    {
        return 'Bearer realm="TMS MCP", resource_metadata="'.$this->issuer.'/.well-known/oauth-protected-resource/mcp", scope="'.$scope.'"'
            .($error!==''?', error="'.$error.'"':'');
    }

    /** @param array<string,mixed> $args */
    public static function str(array $args,string $key,string $default=''): string
    {
        $value=$args[$key]??$default;
        if (!is_string($value)||strlen($value)>8192) { throw new OAuthError('invalid_request','Invalid '.$key.'.'); }
        return $value;
    }

    public static function random(string $prefix): string
    {
        return $prefix.bin2hex(random_bytes(32));
    }

    public static function pkce(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    }

    public function scope(string $raw): string
    {
        if ($raw==='') { return 'tms:read'; }
        $scopes=explode(' ',$raw);
        if (array_diff($scopes,self::SCOPES)!==[]) { throw new OAuthError('invalid_scope','Unsupported scope.'); }
        $level=in_array('tms:delete',$scopes,true)?3:(in_array('tms:write',$scopes,true)?2:1);
        return implode(' ',array_slice(self::SCOPES,0,$level));
    }

    private function checkResource(string $uri): void
    {
        if ($uri!==$this->resource) { throw new OAuthError('invalid_target','Resource must be the canonical TMS MCP URL.'); }
    }

    public function validRedirect(string $uri): bool
    {
        if ($uri===''||strlen($uri)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$uri)) { return false; }
        $url=parse_url($uri);
        if (!is_array($url)||!isset($url['scheme'],$url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['fragment'])) { return false; }
        if ($url['scheme']!=='https'&&!($url['scheme']==='http'&&in_array($url['host'],['127.0.0.1','[::1]'],true))) { return false; }
        if (filter_var($uri,FILTER_VALIDATE_URL)===false) { return false; }
        parse_str($url['query']??'',$query);
        return array_intersect(array_keys($query),['code','state','error','error_description','iss'])===[];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function register(array $args): array
    {
        $name=trim(self::str($args,'client_name','MCP client'));
        $redirects=$args['redirect_uris']??null;
        $method=self::str($args,'token_endpoint_auth_method','client_secret_basic');
        if ($name===''||mb_strlen($name)>100||preg_match('/[\x00-\x1f\x7f]/',$name)
            ||!is_array($redirects)||!array_is_list($redirects)||count($redirects)<1||count($redirects)>10
            ||!in_array($method,['none','client_secret_basic','client_secret_post'],true)) {
            throw new OAuthError('invalid_client_metadata','Invalid client metadata.');
        }
        foreach ($redirects as $uri) {
            if (!is_string($uri)||!$this->validRedirect($uri)) { throw new OAuthError('invalid_redirect_uri','HTTPS or literal loopback redirect required.'); }
        }
        $grants=$args['grant_types']??['authorization_code','refresh_token'];
        $responses=$args['response_types']??['code'];
        if (!is_array($grants)||!array_is_list($grants)||!in_array('authorization_code',$grants,true)
            ||count(array_filter($grants,'is_string'))!==count($grants)||array_diff($grants,['authorization_code','refresh_token'])!==[]||$responses!==['code']) {
            throw new OAuthError('invalid_client_metadata','Only authorization code and refresh token grants are supported.');
        }
        $this->scope(self::str($args,'scope'));
        // Global bounded registration rate; an edge proxy should additionally rate limit per source.
        $count=$this->db->query('SELECT COUNT(*) FROM mcp_oauth_clients WHERE created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
        if ($count===false||(int)$count->fetchColumn()>=1000) { throw new OAuthError('temporarily_unavailable','Registration limit reached.',429); }
        $id=self::random('tms_client_');
        $secret=$method==='none'?null:self::random('tms_cs_');
        $stmt=$this->db->prepare('INSERT INTO mcp_oauth_clients(client_id,client_name,redirect_uris,auth_method,secret_hash,created_at) VALUES(:id,:name,:uris,:method,:hash,UTC_TIMESTAMP())');
        $stmt->execute(['id'=>$id,'name'=>$name,'uris'=>json_encode($redirects,JSON_THROW_ON_ERROR),'method'=>$method,'hash'=>$secret===null?null:hash('sha256',$secret)]);
        $result=['client_id'=>$id,'client_id_issued_at'=>time(),'client_name'=>$name,'redirect_uris'=>$redirects,
            'token_endpoint_auth_method'=>$method,'grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code']];
        if ($secret!==null) { $result['client_secret']=$secret;$result['client_secret_expires_at']=0; }
        return $result;
    }

    /** @return array<string,mixed> */
    private function client(string $id): array
    {
        if (!preg_match('/^tms_client_[a-f0-9]{64}$/D',$id)) { throw new OAuthError('invalid_client','Unknown client.',401); }
        $stmt=$this->db->prepare('SELECT * FROM mcp_oauth_clients WHERE client_id=:id');
        $stmt->execute(['id'=>$id]);
        $row=$stmt->fetch();
        if (!is_array($row)) { throw new OAuthError('invalid_client','Unknown client.',401); }
        return $row;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function authorizeRequest(array $args): array
    {
        $client=$this->client(self::str($args,'client_id'));
        $redirect=self::str($args,'redirect_uri');
        $registered=json_decode((string)$client['redirect_uris'],true,16,JSON_THROW_ON_ERROR);
        if (!is_array($registered)||!in_array($redirect,$registered,true)) { throw new OAuthError('invalid_request','Unregistered redirect URI.'); }
        $state=self::str($args,'state');
        if (strlen($state)>2048||preg_match('/[\x00-\x1f\x7f]/',$state)) { throw new OAuthError('invalid_request','Invalid state.'); }
        $challenge=self::str($args,'code_challenge');
        if (self::str($args,'response_type')!=='code'||self::str($args,'code_challenge_method')!=='S256'
            ||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$challenge)) {
            throw new OAuthError('invalid_request','Authorization code and PKCE S256 required.');
        }
        $resource=self::str($args,'resource',$this->resource);
        $this->checkResource($resource);
        return ['client_id'=>$client['client_id'],'client_name'=>$client['client_name'],'redirect_uri'=>$redirect,
            'state'=>$state,'scope'=>$this->scope(self::str($args,'scope')),'resource'=>$resource,'challenge'=>$challenge];
    }

    /** @param array<string,mixed> $request */
    public function issueCode(int $uid,array $request): string
    {
        $code=self::random('tms_ac_');
        $stmt=$this->db->prepare('INSERT INTO mcp_oauth_codes(code_hash,client_id,user_id,redirect_uri,resource_uri,scope,challenge,expires_at)
            VALUES(:hash,:client,:uid,:redirect,:resource,:scope,:challenge,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))');
        $stmt->execute(['hash'=>hash('sha256',$code),'client'=>$request['client_id'],'uid'=>$uid,'redirect'=>$request['redirect_uri'],
            'resource'=>$request['resource'],'scope'=>$request['scope'],'challenge'=>$request['challenge']]);
        return $code;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function authenticateClient(array $args,string $authorization): array
    {
        $id=self::str($args,'client_id');
        $secret=self::str($args,'client_secret');
        $method=$secret===''?'none':'client_secret_post';
        if ($authorization!=='') {
            if ($secret!==''||!preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/Di',$authorization,$m)) { throw new OAuthError('invalid_client','Invalid client authentication.',401); }
            $decoded=base64_decode($m[1],true);
            if ($decoded===false||!str_contains($decoded,':')) { throw new OAuthError('invalid_client','Invalid client authentication.',401); }
            [$basicId,$secret]=array_map('urldecode',explode(':',$decoded,2));
            if ($id!==''&&$id!==$basicId) { throw new OAuthError('invalid_client','Conflicting client IDs.',401); }
            $id=$basicId;$method='client_secret_basic';
        }
        $client=$this->client($id);
        if ($client['auth_method']!==$method||($method!=='none'&&!hash_equals((string)$client['secret_hash'],hash('sha256',$secret)))) {
            throw new OAuthError('invalid_client','Invalid client authentication.',401);
        }
        return $client;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function token(array $args,string $authorization): array
    {
        $client=$this->authenticateClient($args,$authorization);
        $resource=self::str($args,'resource',$this->resource);
        $this->checkResource($resource);
        $grant=self::str($args,'grant_type');
        $this->db->beginTransaction();
        try {
            if ($grant==='authorization_code') {
                $result=$this->exchangeCode($client,$args,$resource);
            } elseif ($grant==='refresh_token') {
                $result=$this->refresh($client,$args,$resource);
            } else { throw new OAuthError('unsupported_grant_type','Unsupported grant type.'); }
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $client
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function exchangeCode(array $client,array $args,string $resource): array
    {
        $code=self::str($args,'code');$verifier=self::str($args,'code_verifier');
        if (!preg_match('/^tms_ac_[a-f0-9]{64}$/D',$code)||!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$verifier)) {
            throw new OAuthError('invalid_grant','Invalid code or verifier.');
        }
        $hash=hash('sha256',$code);
        $stmt=$this->db->prepare('SELECT c.* FROM mcp_oauth_codes c JOIN users u ON u.id=c.user_id WHERE c.code_hash=:hash
            AND c.expires_at>UTC_TIMESTAMP() AND u.is_active=1 AND u.approved_at IS NOT NULL FOR UPDATE');
        $stmt->execute(['hash'=>$hash]);$row=$stmt->fetch();
        if (!is_array($row)||$row['client_id']!==$client['client_id']||$row['redirect_uri']!==self::str($args,'redirect_uri')
            ||$row['resource_uri']!==$resource||!hash_equals((string)$row['challenge'],self::pkce($verifier))) {
            throw new OAuthError('invalid_grant','Invalid, expired or mismatched authorization code.');
        }
        if ($row['consumed_token_id']!==null) {
            $this->db->prepare('UPDATE mcp_tokens SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE id=:id')
                ->execute(['id'=>$row['consumed_token_id']]);
            $this->db->commit();
            throw new OAuthError('invalid_grant','Authorization code already used.');
        }
        $scope=(string)$row['scope'];
        $access=self::random('tms_oauth_');$refresh=self::random('tms_rt_');
        $stmt=$this->db->prepare('INSERT INTO mcp_tokens(user_id,label,token_hash,can_write,can_delete,expires_at,created_at,
            oauth_client_id,oauth_resource,oauth_refresh_hash,oauth_refresh_expires_at)
            VALUES(:uid,:label,:access,:write,:delete,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP(),
            :client,:resource,:refresh,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY))');
        $stmt->execute(['uid'=>$row['user_id'],'label'=>mb_substr('OAuth: '.(string)$client['client_name'],0,100),
            'access'=>hash('sha256',$access),'write'=>(int)str_contains($scope,'tms:write'),'delete'=>(int)str_contains($scope,'tms:delete'),
            'client'=>$client['client_id'],'resource'=>$resource,'refresh'=>hash('sha256',$refresh)]);
        $this->db->prepare('UPDATE mcp_oauth_codes SET consumed_token_id=:id WHERE code_hash=:hash')->execute(['id'=>(int)$this->db->lastInsertId(),'hash'=>$hash]);
        return $this->tokenResult($access,$refresh,$scope);
    }

    /**
     * @param array<string,mixed> $client
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function refresh(array $client,array $args,string $resource): array
    {
        $refresh=self::str($args,'refresh_token');
        if (!preg_match('/^tms_rt_[a-f0-9]{64}$/D',$refresh)) { throw new OAuthError('invalid_grant','Invalid refresh token.'); }
        $hash=hash('sha256',$refresh);
        $stmt=$this->db->prepare('SELECT m.* FROM mcp_tokens m JOIN users u ON u.id=m.user_id
            WHERE m.oauth_refresh_hash=:hash AND m.oauth_client_id=:client AND m.oauth_resource=:resource
            AND m.revoked_at IS NULL AND m.oauth_refresh_expires_at>UTC_TIMESTAMP()
            AND u.is_active=1 AND u.approved_at IS NOT NULL FOR UPDATE');
        $stmt->execute(['hash'=>$hash,'client'=>$client['client_id'],'resource'=>$resource]);$row=$stmt->fetch();
        if (!is_array($row)) {
            // Reuse of a rotated refresh token invalidates the whole grant.
            $replay=$this->db->prepare('SELECT m.id FROM mcp_oauth_used_refresh r JOIN mcp_tokens m ON m.id=r.token_id
                WHERE r.refresh_hash=:hash AND r.expires_at>UTC_TIMESTAMP() AND m.oauth_client_id=:client AND m.oauth_resource=:resource FOR UPDATE');
            $replay->execute(['hash'=>$hash,'client'=>$client['client_id'],'resource'=>$resource]);
            $id=$replay->fetchColumn();
            if ($id!==false) {
                $this->db->prepare('UPDATE mcp_tokens SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE id=:id')->execute(['id'=>$id]);
                $this->db->commit();
            }
            throw new OAuthError('invalid_grant','Invalid or expired refresh token.');
        }
        $oldScope=$row['can_delete']?implode(' ',self::SCOPES):($row['can_write']?'tms:read tms:write':'tms:read');
        $scope=array_key_exists('scope',$args)?$this->scope(self::str($args,'scope')):$oldScope;
        if (array_diff(explode(' ',$scope),explode(' ',$oldScope))!==[]) { throw new OAuthError('invalid_scope','Refresh cannot expand scope.'); }
        $access=self::random('tms_oauth_');$next=self::random('tms_rt_');
        $this->db->prepare('INSERT INTO mcp_oauth_used_refresh(refresh_hash,token_id,expires_at) VALUES(:hash,:id,:expiry)')
            ->execute(['hash'=>$hash,'id'=>$row['id'],'expiry'=>$row['oauth_refresh_expires_at']]);
        $this->db->prepare('UPDATE mcp_tokens SET token_hash=:access,oauth_refresh_hash=:refresh,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),can_write=:write,can_delete=:delete WHERE id=:id')
            ->execute(['access'=>hash('sha256',$access),'refresh'=>hash('sha256',$next),'write'=>(int)str_contains($scope,'tms:write'),
                'delete'=>(int)str_contains($scope,'tms:delete'),'id'=>$row['id']]);
        return $this->tokenResult($access,$next,$scope);
    }

    /** @return array<string,mixed> */
    private function tokenResult(string $access,string $refresh,string $scope): array
    {
        return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>self::ACCESS_SECONDS,'refresh_token'=>$refresh,'scope'=>$scope];
    }

    /** @param array<string,mixed> $args */
    public function revoke(array $args,string $authorization): void
    {
        $client=$this->authenticateClient($args,$authorization);
        $token=self::str($args,'token');
        $hash=hash('sha256',$token);
        $this->db->prepare('UPDATE mcp_tokens SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE oauth_client_id=:client AND oauth_resource=:resource
            AND (token_hash=:access OR oauth_refresh_hash=:refresh)')
            ->execute(['client'=>$client['client_id'],'resource'=>$this->resource,'access'=>$hash,'refresh'=>$hash]);
    }
}
