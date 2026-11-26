<?php

use App\Filament\Pages\Claude;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Livewire\Livewire;

beforeEach(function () {
    useOAuthTestKeys();

    $this->user = User::factory()->create(['home_location' => 'Riverton, OR']);
});

describe('signing in', function () {
    it('advertises where clients can authorize', function () {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'));
    });

    it('registers clients that redirect to Claude or a local callback', function (string $redirectUri) {
        $this->postJson(route('mcp.oauth.register'), ['client_name' => 'Claude', 'redirect_uris' => [$redirectUri]])
            ->assertCreated();
    })->with([
        'claude.ai' => 'https://claude.ai/api/mcp/auth_callback',
        'claude.com' => 'https://claude.com/api/mcp/auth_callback',
        'a local callback' => 'http://localhost:33418/callback',
    ]);

    it('refuses clients that redirect anywhere else', function (string $redirectUri) {
        $this->postJson(route('mcp.oauth.register'), ['client_name' => 'Claude', 'redirect_uris' => [$redirectUri]])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');

        expect(Client::query()->count())->toBe(0);
    })->with([
        'another domain' => 'https://evil.example/callback',
        'a lookalike domain' => 'https://claude.ai.evil.example/callback',
        'a custom scheme' => 'evil://callback',
    ]);

    it('rate limits client registration', function () {
        $register = fn () => $this->postJson(route('mcp.oauth.register'), ['redirect_uris' => ['https://evil.example/callback']]);

        foreach (range(1, 30) as $attempt) {
            $register()->assertStatus(400);
        }

        $register()->assertTooManyRequests();
    });

    it('sends guests to the login page and back to the consent screen', function () {
        $clientId = $this->postJson(route('mcp.oauth.register'), [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->json('client_id');

        $this->get(route('passport.authorizations.authorize', [
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => str_repeat('c', 43),
            'code_challenge_method' => 'S256',
        ]))->assertRedirect(route('filament.app.auth.login'));

        expect(session('url.intended'))->toStartWith(route('passport.authorizations.authorize'))
            ->toContain("client_id={$clientId}");

        Livewire::test(Login::class)
            ->fillForm(['email' => $this->user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(session('url.intended'));
    });

    it('shows who is asking and where they will be sent, escaping the client\'s name', function () {
        $this->user->update(['email' => 'family@example.com']);

        startOAuthFlow($this->user, '<script>alert(1)</script>')['consent']
            ->assertOk()
            ->assertSee('family@example.com')
            ->assertSee('https://claude.ai/api/mcp/auth_callback')
            ->assertSee('&lt;script&gt;', escape: false)
            ->assertDontSee('<script>alert(1)</script>', escape: false);
    });

    it('issues a token that acts as the user who approved it', function () {
        User::factory()->create(['home_location' => 'Bayport, WA']);

        $tokens = approveOAuthFlow(startOAuthFlow($this->user));

        postToMcpWithToken($tokens['access_token'])
            ->assertOk()
            ->assertSee('Riverton, OR')
            ->assertDontSee('Bayport, WA');
    });

    it('turns away a request without a token', function () {
        $this->postJson(route('mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized();
    });

    it('issues nothing when the user cancels', function () {
        $flow = startOAuthFlow($this->user);

        $location = $this->delete(route('passport.authorizations.deny'), [
            'state' => '',
            'client_id' => $flow['client_id'],
            'auth_token' => $flow['consent']->viewData('authToken'),
        ])->assertRedirect()->headers->get('Location');

        expect($location)->toStartWith($flow['redirect_uri'])->toContain('error=access_denied')
            ->and(Token::query()->count())->toBe(0);
    });

    it('does not offer the device code grant', function () {
        $this->postJson('/oauth/device/code', ['client_id' => 'anything'])->assertNotFound();
    });
});

describe('the Connect Claude page', function () {
    it('shows how to connect Claude using this server\'s URL', function () {
        $this->actingAs($this->user);

        Livewire::test(Claude::class)
            ->assertSee(route('mcp'))
            ->assertSee('claude mcp add --transport http --scope user sunshine '.route('mcp'));
    });

    it('lists only the apps the user has connected', function () {
        $mine = approveOAuthFlow(startOAuthFlow($this->user, 'Claude'));
        $someoneElses = approveOAuthFlow(startOAuthFlow(User::factory()->create(), 'Other app'));

        $this->actingAs($this->user);

        Livewire::test(Claude::class)
            ->assertCanSeeTableRecords([Client::find($mine['client_id'])])
            ->assertCanNotSeeTableRecords([Client::find($someoneElses['client_id'])]);
    });

    it('disconnects an app so its tokens stop working', function () {
        $tokens = approveOAuthFlow(startOAuthFlow($this->user));
        $client = Client::find($tokens['client_id']);

        $this->actingAs($this->user);

        Livewire::test(Claude::class)
            ->callAction(TestAction::make('disconnect')->table($client))
            ->assertCanNotSeeTableRecords([$client]);

        postToMcpWithToken($tokens['access_token'])->assertUnauthorized();

        $this->post(route('passport.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $tokens['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ])->assertBadRequest();
    });

    it('leaves other users connected to the same app', function () {
        $flow = startOAuthFlow($this->user);
        approveOAuthFlow($flow);

        $otherUser = User::factory()->create();
        $otherTokens = approveOAuthFlow([
            ...$flow,
            'consent' => requestConsent($otherUser, $flow['client_id'], $flow['redirect_uri'], $flow['code_verifier']),
        ]);

        $this->actingAs($this->user);

        Livewire::test(Claude::class)
            ->callAction(TestAction::make('disconnect')->table(Client::find($flow['client_id'])));

        expect(Client::query()->whereKey($flow['client_id'])->exists())->toBeTrue()
            ->and(Token::query()->where('user_id', $otherUser->id)->where('revoked', false)->count())->toBe(1);

        postToMcpWithToken($otherTokens['access_token'])->assertOk();
    });
});
