<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('pages cannot be framed by other sites and send the security headers', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('only scripts from the app or with this response\'s nonce may run', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $csp, $nonce);

    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("object-src 'none'")
        ->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($nonce)->toHaveCount(2);

    // Every inline script on the page carries the nonce, so the browser runs it.
    preg_match_all('/<script(?![^>]*\ssrc=)([^>]*)>/', $response->getContent(), $inlineScripts);
    expect($inlineScripts[1])->not->toBeEmpty()
        ->each->toContain('nonce="'.$nonce[1].'"');
});

test('responses other than HTML pages get no content security policy', function () {
    Route::middleware('web')->get('/_test/json', fn () => ['ok' => true]);

    $this->get('/_test/json')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeaderMissing('Content-Security-Policy');
});
