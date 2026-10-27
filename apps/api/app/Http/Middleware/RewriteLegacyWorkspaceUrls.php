<?php

namespace App\Http\Middleware;

use Closure;
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
                $response->setContent($this->rewrite($content));
            }
        }

        return $response;
    }

    private function rewrite(string $value): string
    {
        return preg_replace_callback(
            '~(?P<origin>https?://[^/"\'<>\s]+)?/app/workspaces/[0-9a-f-]{36}(?P<suffix>/[^?#"\'<>\s]*)?~i',
            function (array $matches): string {
                $origin = $matches['origin'] ?? '';
                $suffix = $matches['suffix'] ?? '';
                $suffix = trim($suffix, '/');

                $path = match ($suffix) {
                    '', 'analytics' => '/dashboard/main',
                    'campaigns' => '/mailer/campaigns',
                    default => str_starts_with($suffix, 'campaigns/')
                        ? '/mailer/campaigns/'.substr($suffix, strlen('campaigns/'))
                        : '/'.$suffix,
                };

                return $origin.$path;
            },
            $value,
        ) ?? $value;
    }
}
