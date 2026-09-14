<?php

declare(strict_types=1);

namespace Tms\Tests\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Notification\TelegramSystemSettingsRepository;
use Tms\Infrastructure\SecretBox;
use Tms\Infrastructure\TelegramBotSender;
use Tms\Infrastructure\TelegramConfigurationProvider;

final class TelegramConfigurationTest extends TestCase
{
    private PDO $db;
    private SecretBox $secretBox;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('CREATE TABLE telegram_system_settings (
            id INTEGER PRIMARY KEY,
            bot_name TEXT NOT NULL DEFAULT \'\',
            bot_token_ciphertext TEXT NULL,
            webhook_secret_ciphertext TEXT NULL,
            proxy_enabled INTEGER NOT NULL DEFAULT 0,
            proxy_url_ciphertext TEXT NULL
        )');
        $this->secretBox = new SecretBox(base64_encode(str_repeat('t', 32)));
    }

    public function testStoredConfigurationOverridesEnvironmentFallback(): void
    {
        $stmt = $this->db->prepare('INSERT INTO telegram_system_settings
            (id, bot_name, bot_token_ciphertext, webhook_secret_ciphertext, proxy_enabled, proxy_url_ciphertext)
            VALUES (1, :name, :token, :secret, 1, :proxy)');
        $stmt->execute([
            'name' => '@stored_bot',
            'token' => $this->secretBox->encrypt('stored-token'),
            'secret' => $this->secretBox->encrypt('stored-secret'),
            'proxy' => $this->secretBox->encrypt('socks5h://proxy.example:1080'),
        ]);

        $provider = new TelegramConfigurationProvider(
            new TelegramSystemSettingsRepository($this->db),
            $this->secretBox,
            '@env_bot',
            'env-token',
            'env-secret',
            false,
            '',
        );
        $config = $provider->get();

        self::assertSame('@stored_bot', $config->botName);
        self::assertSame('stored-token', $config->botToken);
        self::assertSame('stored-secret', $config->webhookSecret);
        self::assertTrue($config->proxyEnabled);
        self::assertSame('socks5h://proxy.example:1080', $config->proxyUrl);
    }

    public function testTelegramSenderUsesConfiguredProxyForApiCalls(): void
    {
        $provider = new TelegramConfigurationProvider(
            new TelegramSystemSettingsRepository($this->db),
            $this->secretBox,
            '@env_bot',
            '123456:token-value',
            'webhook-secret',
            true,
            'http://proxy.example:3128',
        );
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], '{"ok":true,"result":{"id":1}}'),
            new Response(200, [], '{"ok":true,"result":true}'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $sender = new TelegramBotSender(new Client(['handler' => $stack]), $provider);

        self::assertTrue($sender->probe()->success);
        self::assertTrue($sender->setWebhook('https://tasks.example/telegram/webhook')->success);
        self::assertCount(2, $history);
        self::assertSame('http://proxy.example:3128', $history[0]['options']['proxy']);
        self::assertSame('http://proxy.example:3128', $history[1]['options']['proxy']);
    }

    public function testTelegramApiFailureReturnsSafeStructuredResult(): void
    {
        $provider = new TelegramConfigurationProvider(
            new TelegramSystemSettingsRepository($this->db),
            $this->secretBox,
            '',
            '123456:bad-token',
            'webhook-secret',
        );
        $mock = new MockHandler([
            new Response(401, [], '{"ok":false,"description":"Unauthorized"}'),
        ]);
        $sender = new TelegramBotSender(new Client(['handler' => HandlerStack::create($mock)]), $provider);

        $result = $sender->probe();
        self::assertFalse($result->success);
        self::assertSame('telegram_http_error', $result->code);
        self::assertSame(401, $result->httpStatus);
        self::assertSame('Unauthorized', $result->description);
    }
}
