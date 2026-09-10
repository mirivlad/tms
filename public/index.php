<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Tms\Application\ApplicationFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$app = (new ApplicationFactory())->create();
$app->run();
