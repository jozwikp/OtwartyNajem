<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface language: the user's choice, or for guests the browser's preferred language.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = Locales::codes();
        $locale = $request->user()?->locale;

        if (! in_array($locale, $supported, true)) {
            $locale = $request->getPreferredLanguage($supported) ?? config('app.locale');
        }

        App::setLocale($locale);

        // Our own texts use Polish keys, the framework's use English ones – never mix languages.
        app('translator')->setFallback($locale);

        return $next($request);
    }
}
