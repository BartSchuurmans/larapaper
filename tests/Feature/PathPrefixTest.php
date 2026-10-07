<?php

// A reverse proxy serving LaraPaper under a sub-path (e.g. https://example.com/larapaper/) strips
// the prefix and passes it in X-Forwarded-Prefix. Laravel follows it when TRUSTED_PROXIES
// includes the proxy.

use App\Models\User;

it('puts a trusted proxy prefix in generated URLs', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    $this->get('/login', ['X-Forwarded-Prefix' => '/larapaper'])
        ->assertOk()
        ->assertSee('action="http://localhost/larapaper/login"', false)
        ->assertSee('href="http://localhost/larapaper/register"', false);
});

it('redirects to the login page under a prefix', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    $this->get('/dashboard', ['X-Forwarded-Prefix' => '/larapaper'])
        ->assertRedirect('http://localhost/larapaper/login');
});

it('answers the root under a prefix', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    $this->get('/', ['X-Forwarded-Prefix' => '/larapaper'])
        ->assertOk()
        ->assertSee('http://localhost/larapaper/', false);
});

it('keeps the prefix after logging in', function (): void {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'], ['X-Forwarded-Prefix' => '/larapaper'])
        ->assertRedirect('http://localhost/larapaper/dashboard');
});

it('ignores the prefix from an untrusted client', function (): void {
    config(['trustedproxy.proxies' => []]);

    $this->get('/login', ['X-Forwarded-Prefix' => '/larapaper'])
        ->assertOk()
        ->assertDontSee('/larapaper/', false);
});
