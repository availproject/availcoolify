<?php

use App\Models\OauthSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Clerk refuses an authorization request without PKCE, even for a confidential client.
it('sends a PKCE challenge when redirecting to Clerk', function () {
    OauthSetting::updateOrCreate(['provider' => 'clerk'], [
        'enabled' => true,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'redirect_uri' => 'https://deploy.example.test/auth/clerk/callback',
        'base_url' => 'https://example.clerk.accounts.dev',
    ]);

    $location = $this->get('/auth/clerk/redirect')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://example.clerk.accounts.dev/oauth/authorize')
        ->and($query['code_challenge_method'] ?? null)->toBe('S256')
        ->and($query['code_challenge'] ?? '')->not->toBeEmpty()
        ->and(session('code_verifier'))->not->toBeEmpty();
});
