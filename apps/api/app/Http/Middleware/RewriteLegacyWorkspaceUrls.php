<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RewriteLegacyWorkspaceUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $location = $response->headers->get('Location');
        if (is_string($location) && $location !== '') {
            $response->headers->set('Location', $this->rewrite($location));
        }

        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            $content = $response->getContent();
            if (is_string($content) && str_contains($content, '/app/workspaces/')) {
                $originalView = $response->original instanceof View ? $response->original : null;
                $response->setContent($this->rewrite($content));
                if ($originalView !== null) {
                    $response->original = $originalView;
                }
            }
        }

        return $response;
    }

    private function rewrite(string $value): string
    {
        $separator = '(?:/|\\\\/)';

        return preg_replace_callback(
            '~(?P<origin>https?:'.$separator.$separator.'[^"\'<>\s]+?)?'.$separator.'app'.$separator.'workspaces'.$separator.'[0-9a-f-]{36}(?P<suffix>(?:'.$separator.'[^?#"\'<>\s]*)?)~i',
            function (array $matches): string {
                $escapedSlashes = str_contains($matches[0], '\\/');
                $origin = str_replace('\\/', '/', $matches['origin'] ?? '');
                $suffix = str_replace('\\/', '/', $matches['suffix'] ?? '');
                $suffix = trim($suffix, '/');

                $path = match ($suffix) {
                    '', 'analytics' => '/dashboard/main',
                    'campaigns' => '/mailer/campaigns',
                    default => str_starts_with($suffix, 'campaigns/')
                        ? '/mailer/campaigns/'.substr($suffix, strlen('campaigns/'))
                        : '/'.$suffix,
                };

                $rewritten = $origin.$path;

                return $escapedSlashes ? str_replace('/', '\\/', $rewritten) : $rewritten;
            },
            $value,
        ) ?? $value;
    }
}
