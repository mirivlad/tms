<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tms\Domain\Mcp\McpTokenRepository;
use Tms\Domain\User\UserRepository;
use Tms\Infrastructure\Database;

require dirname(__DIR__).'/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$env=static fn(string $key,string $default=''): string => (string)(getenv($key) !== false ? getenv($key) : ($_ENV[$key]??$default));
try {
    $db=(new Database(['host'=>$env('DB_HOST'),'port'=>$env('DB_PORT','3306'),'name'=>$env('DB_NAME'),'user'=>$env('DB_USER'),'password'=>$env('DB_PASS')]))->connect();
    $user=(new UserRepository($db))->findByUsername($argv[2]??'');
    $command=$argv[1]??'';
    if ($user===null) { throw new RuntimeException('Unknown user.'); }
    $tokens=new McpTokenRepository($db);
    switch($command) {
        case 'issue':
            if (!$user->isActive||!$user->isApproved) { throw new RuntimeException('User inactive or unapproved.'); }
            $scope=$argv[4]??'read';
            if (!in_array($scope,['read','write','delete'],true)||!ctype_digit($argv[5]??'30')) { throw new RuntimeException('Invalid scope or expiry.'); }
            echo $tokens->issue($user->id,$argv[3]??'',$scope!=='read',(int)($argv[5]??30),$scope==='delete').PHP_EOL;
            break;
        case 'list':
            echo json_encode($tokens->listForUser($user->id),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
            break;
        case 'revoke':
            if (!$tokens->revoke($user->id,(int)($argv[3]??0))) { throw new RuntimeException('Token unavailable.'); }
            echo "Revoked.\n";
            break;
        default: throw new RuntimeException('Usage: mcp-token.php issue USER LABEL [read|write|delete] [days: 0 for permanent, 1–365 otherwise] | list USER | revoke USER ID');
    }
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
