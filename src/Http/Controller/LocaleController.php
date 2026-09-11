<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tms\I18n\Translator;

final class LocaleController
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function switch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $locale = is_array($body) && is_string($body['locale'] ?? null)
            ? (string) $body['locale']
            : '';

        if (!$this->translator->setLocale($locale)) {
            $response->getBody()->write($this->translator->trans('validation.invalid_locale'));
            return $response->withStatus(422)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $response->withHeader('Location', $this->returnPath($request))->withStatus(302);
    }

    private function returnPath(ServerRequestInterface $request): string
    {
        $referer = trim($request->getHeaderLine('Referer'));
        if ($referer === '') {
            return '/';
        }

        $parts = parse_url($referer);
        if ($parts === false) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        $query = $parts['query'] ?? null;
        return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
