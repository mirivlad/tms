<?php

declare(strict_types=1);

namespace Tms\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tms\Http\Controller\LocaleController;
use Tms\I18n\Translator;

final class LocaleControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testValidLocaleIsStoredAndRefererPathIsPreserved(): void
    {
        $translator = $this->translator();
        $controller = new LocaleController($translator);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/locale')
            ->withHeader('Referer', 'https://tms.example.test/tasks?priority=urgent')
            ->withParsedBody(['locale' => 'ru']);

        $response = $controller->switch($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/tasks?priority=urgent', $response->getHeaderLine('Location'));
        self::assertSame('ru', $translator->locale());
    }

    public function testUnsupportedLocaleIsRejected(): void
    {
        $controller = new LocaleController($this->translator());
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/locale')
            ->withParsedBody(['locale' => 'de']);

        $response = $controller->switch($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Unsupported interface language.', (string) $response->getBody());
    }

    private function translator(): Translator
    {
        return new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en');
    }
}
