<?php

declare(strict_types=1);

namespace App\Core\View;

use App\Core\Http\Response;
use Twig\Environment;

/**
 * Twig rendering. Autoescaping is always on (plan.md §19.2) and undefined
 * variables are errors, so a typo in a template fails loudly in development
 * instead of rendering an empty string.
 */
final class View
{
    public function __construct(private readonly Environment $twig) {}

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /** @param array<string, mixed> $data */
    public function response(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->render($template, $data), $status);
    }

    public function twig(): Environment
    {
        return $this->twig;
    }
}
