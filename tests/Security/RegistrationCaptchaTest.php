<?php

declare(strict_types=1);

namespace Tms\Tests\Security;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Tms\Security\RegistrationCaptcha;

final class RegistrationCaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testIssuedChallengeCanBeSolvedOnce(): void
    {
        $captcha = new RegistrationCaptcha();
        $svg = $captcha->issue();
        $code = $this->decode($svg);

        self::assertMatchesRegularExpression('/^\d{6}$/D', $code);
        self::assertTrue($captcha->verify($code));
        self::assertFalse($captcha->verify($code));
    }

    public function testWrongAnswerConsumesChallenge(): void
    {
        $captcha = new RegistrationCaptcha();
        $code = $this->decode($captcha->issue());
        $wrong = $code === '000000' ? '111111' : '000000';

        self::assertFalse($captcha->verify($wrong));
        self::assertFalse($captcha->verify($code));
    }

    private function decode(string $svg): string
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($svg));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');
        $groups = $xpath->query('//svg:g');
        self::assertNotFalse($groups);
        self::assertCount(6, $groups);

        $rectangleToSegment = [
            '4,0,18,5' => 'a', '21,4,5,19' => 'b', '21,27,5,19' => 'c',
            '4,45,18,5' => 'd', '0,27,5,19' => 'e', '0,4,5,19' => 'f', '4,23,18,5' => 'g',
        ];
        $segmentsToDigit = [
            'abcdef' => '0', 'bc' => '1', 'abdeg' => '2', 'abcdg' => '3', 'bcfg' => '4',
            'acdfg' => '5', 'acdefg' => '6', 'abc' => '7', 'abcdefg' => '8', 'abcdfg' => '9',
        ];
        $result = '';
        foreach ($groups as $group) {
            self::assertInstanceOf(DOMElement::class, $group);
            $segments = [];
            foreach ($group->getElementsByTagName('rect') as $rect) {
                $key = implode(',', [$rect->getAttribute('x'), $rect->getAttribute('y'), $rect->getAttribute('width'), $rect->getAttribute('height')]);
                if (isset($rectangleToSegment[$key])) {
                    $segments[] = $rectangleToSegment[$key];
                }
            }
            sort($segments);
            $signature = implode('', $segments);
            self::assertArrayHasKey($signature, $segmentsToDigit);
            $result .= $segmentsToDigit[$signature];
        }
        return $result;
    }
}
