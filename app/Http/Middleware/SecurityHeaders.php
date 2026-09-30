<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side protection: the app can't be framed by other sites, and pages may only run
 * scripts from this server or ones carrying this response's nonce – an injected <script>
 * or a script from another domain is refused.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Vite, Livewire and Flux add this nonce to the scripts they print.
        Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($this->needsContentSecurityPolicy($response)) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * Only HTML pages – invoice files and Livewire's JSON don't run scripts. The framework's
     * error page (debug mode only) has inline scripts of its own, so it's left alone.
     */
    protected function needsContentSecurityPolicy(Response $response): bool
    {
        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        return ! ($response->isServerError() && config('app.debug'));
    }

    protected function contentSecurityPolicy(): string
    {
        $self = ["'self'"];
        // `npm run dev`: assets come from Vite's own server and reload over a websocket.
        $vite = Vite::isRunningHot() ? $this->viteDevServer() : [];

        $directives = [
            'default-src' => $self,
            // Alpine (Livewire, Flux) evaluates x-data and wire: expressions – that needs 'unsafe-eval'.
            'script-src' => [...$self, "'nonce-".Vite::cspNonce()."'", "'unsafe-eval'", ...$vite],
            // Flux positions menus and tooltips with inline styles.
            'style-src' => [...$self, "'unsafe-inline'", ...$vite],
            'font-src' => [...$self, 'data:', ...$vite],
            'img-src' => [...$self, 'data:', 'blob:'],
            'connect-src' => [...$self, ...$vite, ...array_map(fn ($url) => Str::replaceFirst('http', 'ws', $url), $vite)],
            // The invoice preview shows the file in an iframe.
            'frame-src' => $self,
            'frame-ancestors' => $self,
            'form-action' => $self,
            'base-uri' => $self,
            'object-src' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive) => $directive.' '.implode(' ', $sources))
            ->implode('; ');
    }

    /**
     * @return list<string>
     */
    protected function viteDevServer(): array
    {
        $url = trim((string) file_get_contents(public_path('hot')));

        return [$url, Str::replace(['localhost', '127.0.0.1'], '[::1]', $url)];
    }
}
