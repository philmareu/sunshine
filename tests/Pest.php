<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sign OAuth tokens with a throwaway key, so tests don't depend on storage/oauth-*.key existing.
 */
function useOAuthTestKeys(): void
{
    static $keys = null;

    if ($keys === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);
        $keys = [$privateKey, openssl_pkey_get_details($key)['key']];
    }

    config(['passport.private_key' => $keys[0], 'passport.public_key' => $keys[1]]);
}

/**
 * Run the OAuth flow as Claude would, up to the consent screen.
 *
 * @return array{client_id: string, redirect_uri: string, code_verifier: string, consent: TestResponse}
 */
function startOAuthFlow(User $user, string $clientName = 'Claude'): array
{
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $codeVerifier = str_repeat('v', 64);

    $clientId = test()->postJson(route('mcp.oauth.register'), [
        'client_name' => $clientName,
        'redirect_uris' => [$redirectUri],
    ])->assertCreated()->json('client_id');

    return [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'code_verifier' => $codeVerifier,
        'consent' => requestConsent($user, $clientId, $redirectUri, $codeVerifier),
    ];
}

function requestConsent(User $user, string $clientId, string $redirectUri, string $codeVerifier): TestResponse
{
    return test()->actingAs($user)->get(route('passport.authorizations.authorize', [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'client-state',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]));
}

/**
 * Approve the consent screen and swap the code for tokens.
 *
 * @param  array{client_id: string, redirect_uri: string, code_verifier: string, consent: TestResponse}  $flow
 * @return array{client_id: string, access_token: string, refresh_token: string}
 */
function approveOAuthFlow(array $flow): array
{
    $location = test()->post(route('passport.authorizations.approve'), [
        'state' => '',
        'client_id' => $flow['client_id'],
        'auth_token' => $flow['consent']->viewData('authToken'),
    ])->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith($flow['redirect_uri'])
        ->and($query['state'])->toBe('client-state');

    $tokens = test()->post(route('passport.token'), [
        'grant_type' => 'authorization_code',
        'client_id' => $flow['client_id'],
        'redirect_uri' => $flow['redirect_uri'],
        'code_verifier' => $flow['code_verifier'],
        'code' => $query['code'],
    ])->assertOk();

    return [
        'client_id' => $flow['client_id'],
        'access_token' => $tokens->json('access_token'),
        'refresh_token' => $tokens->json('refresh_token'),
    ];
}

/**
 * Call get-preferences with an access token, forgetting who the last request authenticated as, so the call proves
 * its own token.
 */
function postToMcpWithToken(string $accessToken): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($accessToken)->postJson(route('mcp'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'get-preferences', 'arguments' => []],
    ]);
}
