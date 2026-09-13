<?php

declare(strict_types=1);

namespace Tms\Security;

final class RegistrationCaptcha
{
    private const SESSION_KEY = '_registration_captcha';
    private const DIGITS = 6;
    private const TTL_SECONDS = 600;
    private const SEGMENTS = [
        '0' => ['a','b','c','d','e','f'], '1' => ['b','c'], '2' => ['a','b','g','e','d'],
        '3' => ['a','b','c','d','g'], '4' => ['f','g','b','c'], '5' => ['a','f','g','c','d'],
        '6' => ['a','f','g','e','c','d'], '7' => ['a','b','c'], '8' => ['a','b','c','d','e','f','g'],
        '9' => ['a','b','c','d','f','g'],
    ];

    /** Returns a no-dependency SVG visual challenge and stores only its hash in the session. */
    public function issue(): string
    {
        $code = '';
        for ($i = 0; $i < self::DIGITS; ++$i) {
            $code .= (string) random_int(0, 9);
        }
        $_SESSION[self::SESSION_KEY] = [
            'hash' => hash('sha256', $code),
            'expires_at' => time() + self::TTL_SECONDS,
        ];
        return $this->renderSvg($code);
    }

    public function verify(string $candidate): bool
    {
        $challenge = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($challenge) || !is_string($challenge['hash'] ?? null) || !is_int($challenge['expires_at'] ?? null)) {
            return false;
        }
        $candidate = trim($candidate);
        if ($challenge['expires_at'] < time() || preg_match('/^\d{6}$/D', $candidate) !== 1) {
            return false;
        }
        return hash_equals($challenge['hash'], hash('sha256', $candidate));
    }

    private function renderSvg(string $code): string
    {
        $width = 228;
        $height = 72;
        $svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="228" height="72" viewBox="0 0 228 72" role="img">',
            '<rect width="228" height="72" rx="8" fill="#f4f6f8"/>'];
        for ($i = 0; $i < 13; ++$i) {
            $x1=random_int(0,$width); $y1=random_int(0,$height); $x2=random_int(0,$width); $y2=random_int(0,$height);
            $shade=random_int(145,205);
            $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="rgb(%d,%d,%d)" stroke-width="%d" opacity=".55"/>', $x1,$y1,$x2,$y2,$shade,$shade,$shade,random_int(1,2));
        }
        foreach (str_split($code) as $index => $digit) {
            $x = 11 + $index * 36 + random_int(-2, 2);
            $y = 9 + random_int(-3, 3);
            $angle = random_int(-11, 11);
            $svg[] = sprintf('<g transform="translate(%d %d) rotate(%d 13 25)" fill="#1f2937">', $x, $y, $angle);
            foreach (self::SEGMENTS[$digit] as $segment) {
                [$sx,$sy,$sw,$sh] = $this->segment($segment);
                $svg[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="2"/>', $sx,$sy,$sw,$sh);
            }
            $svg[] = '</g>';
        }
        for ($i = 0; $i < 45; ++$i) {
            $x=random_int(2,$width-2); $y=random_int(2,$height-2); $r=random_int(1,2);
            $svg[] = sprintf('<circle cx="%d" cy="%d" r="%d" fill="#64748b" opacity=".45"/>', $x,$y,$r);
        }
        $svg[]='</svg>';
        return implode('', $svg);
    }

    /** @return array{int,int,int,int} */
    private function segment(string $name): array
    {
        return match ($name) {
            'a' => [4,0,18,5], 'b' => [21,4,5,19], 'c' => [21,27,5,19],
            'd' => [4,45,18,5], 'e' => [0,27,5,19], 'f' => [0,4,5,19], 'g' => [4,23,18,5],
            default => [0,0,0,0],
        };
    }
}
