<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;

function trustProxies(?string $value): void
{
    config(['app.trusted_proxies' => $value]);
    (fn () => $this->configureProxies())->call(new AppServiceProvider(app()));
}

beforeEach(function () {
    Route::get('/_test/ip', fn () => request()->ip());
});

afterEach(function () {
    TrustProxies::flushState();
});

test('behind Cloudflare the visitor\'s real address is used', function () {
    trustProxies('cloudflare');

    $this->withServerVariables(['REMOTE_ADDR' => '172.64.10.20'])
        ->get('/_test/ip', ['X-Forwarded-For' => '83.20.1.2'])
        ->assertSee('83.20.1.2');
});

test('a forged header from outside Cloudflare is ignored', function () {
    trustProxies('cloudflare');

    $this->withServerVariables(['REMOTE_ADDR' => '91.1.2.3'])
        ->get('/_test/ip', ['X-Forwarded-For' => '83.20.1.2'])
        ->assertSee('91.1.2.3');
});

test('without a configured proxy the header is ignored', function () {
    trustProxies(null);

    $this->withServerVariables(['REMOTE_ADDR' => '172.64.10.20'])
        ->get('/_test/ip', ['X-Forwarded-For' => '83.20.1.2'])
        ->assertSee('172.64.10.20');
});
