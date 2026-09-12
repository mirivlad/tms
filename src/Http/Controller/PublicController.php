<?php

declare(strict_types=1);

namespace Tms\Http\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class PublicController
{
    public function __construct(private readonly Twig $view)
    {
    }

    public function firstSteps(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'public/first_steps.twig', [
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    public function privacy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'public/privacy.twig', [
            'csrf_token' => $this->csrfToken($request),
        ]);
    }

    private function csrfToken(ServerRequestInterface $request): string
    {
        $token = $request->getAttribute('csrf_token');
        return is_string($token) ? $token : '';
    }
}
