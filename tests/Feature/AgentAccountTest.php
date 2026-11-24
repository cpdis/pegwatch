<?php

namespace Tests\Feature;

use App\Mcp\Servers\PegwatchAccountServer;
use App\Mcp\Tools\Account\ConfirmWatch;
use App\Mcp\Tools\Account\DeleteWatch;
use App\Mcp\Tools\Account\ListMyWatches;
use App\Mcp\Tools\Account\ResumeWatch;
use App\Mcp\Tools\Account\StartWatch;
use App\Mcp\Tools\Account\StopWatch;
use App\Mcp\Tools\Account\UpdateWatch;
use App\Models\User;
use App\Models\WatchArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\TestCase;

/** An agent the person signed in (OAuth) managing their watches: /mcp/account and /api/v1/me. */
class AgentAccountTest extends TestCase
{
    use RefreshDatabase;

    private const MCP = ['Accept' => 'application/json, text/event-stream'];

    /** @var array{0: string, 1: string}|null one signing key pair for the whole class */
    private static ?array $keys = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$keys) {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            openssl_pkey_export($key, $private);
            self::$keys = [$private, openssl_pkey_get_details($key)['key']];
        }
        config(['passport.private_key' => self::$keys[0], 'passport.public_key' => self::$keys[1]]);
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, extract_at, geom)
             values ('E  1500001', 'E 15/1', 'EXPLORATION LICENCE', 'E', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', 100, now(),
                     'SRID=4326;MULTIPOLYGON(((121.5 -30.8,121.51 -30.8,121.51 -30.79,121.5 -30.79,121.5 -30.8)))'::geometry)"
        );
    }

    protected function tearDown(): void
    {
        Passport::$keyPath = null;
        parent::tearDown();
    }

    private function user(string $email = 'pat@example.com'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    private function watch(User $user, array $attributes = []): WatchArea
    {
        $area = $user->watchAreas()->create($attributes + [
            'name' => 'Kalgoorlie', 'kind' => 'circle', 'latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 5,
            'event_types' => WatchArea::eventTypesFor(['death', 'release']),
        ]);
        $area->confirm();

        return $area->refresh();
    }

    public function test_without_a_token_the_account_server_points_agents_at_oauth(): void
    {
        $this->postJson('/mcp/account', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], self::MCP)
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/account').'"');

        $this->getJson('/.well-known/oauth-protected-resource/mcp/account')->assertOk()
            ->assertJsonPath('resource', url('/mcp/account'))
            ->assertJsonPath('authorization_servers.0', url('/'));
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'))
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('jwks_uri', route('oauth.jwks'));
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('bearer_methods_supported', ['header']);
        $jwk = $this->getJson('/oauth/jwks')->assertOk()->json('keys.0');
        $this->assertSame(['RSA', 'RS256', 'AQAB'], [$jwk['kty'], $jwk['alg'], $jwk['e']]);
        config(['passport.public_key' => "-----BEGIN PUBLIC KEY-----\nnot a key\n-----END PUBLIC KEY-----"]);
        $this->getJson('/oauth/jwks')->assertOk()->assertExactJson(['keys' => []]);
        config(['passport.public_key' => self::$keys[1]]);
        $this->getJson('/api/v1/me/watches')->assertUnauthorized();

        // No keys in config or on disk: say so instead of failing in the guard.
        config(['passport.public_key' => null]);
        Passport::loadKeysFrom(sys_get_temp_dir().'/pegwatch-no-oauth-keys');
        $this->getJson('/api/v1/me/watches')->assertServiceUnavailable()->assertJsonPath('message', fn ($m) => str_contains($m, 'not set up'));
    }

    public function test_an_agent_registers_the_person_approves_and_the_token_works_until_disconnected(): void
    {
        $callback = 'https://agent.example/callback';
        $client = $this->postJson('/oauth/register', ['client_name' => 'Test agent', 'redirect_uris' => [$callback]])
            ->assertCreated()->json('client_id');

        $user = $this->user();
        $this->watch($user);
        $verifier = Str::random(64);
        $query = http_build_query([
            'client_id' => $client, 'redirect_uri' => $callback, 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);

        // A guest is sent to sign in first, and comes back here after.
        $this->get('/oauth/authorize?'.$query)->assertRedirect(route('login'));

        $this->actingAs($user)->get('/oauth/authorize?'.$query)->assertOk()
            ->assertSee('Let Test agent use your Pegwatch account?')->assertSee('agent.example')->assertSee('pat@example.com');
        $redirect = $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith($callback.'?', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $params);
        $this->assertSame('xyz', $params['state']);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client, 'redirect_uri' => $callback,
            'code_verifier' => $verifier, 'code' => $params['code'],
        ])->assertOk()->json('access_token');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $bearer = self::MCP + ['Authorization' => "Bearer $token"];
        $init = $this->postJson('/mcp/account', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], $bearer)->assertOk();
        $this->assertSame('Pegwatch (your account)', $init->json('result.serverInfo.name'));
        $call = $this->postJson('/mcp/account', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_my_watches', 'arguments' => (object) []]],
            $bearer + ['MCP-Protocol-Version' => '2025-06-18'])->assertOk();
        $this->assertSame('Kalgoorlie', $call->json('result.structuredContent.watches.0.name'));
        $this->getJson('/api/v1/me/watches', ['Authorization' => "Bearer $token"])->assertOk()->assertJsonPath('watches.0.status', 'watching');

        $this->assertSame(['Test agent'], array_column($user->connectedAgents(), 'name'));
        $user->disconnectAgents();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me/watches', ['Authorization' => "Bearer $token"])->assertUnauthorized();
        $this->assertSame([], $user->connectedAgents());
    }

    public function test_the_account_tools_list_start_change_stop_and_delete_watches(): void
    {
        $user = $this->user();
        Passport::actingAs($user, ['mcp:use'], 'api');

        PegwatchAccountServer::tool(ListMyWatches::class)->assertOk()->assertSee('"watches":[]');

        $id = PegwatchAccountServer::tool(StartWatch::class, ['tenement' => 'e15/1', 'alerts' => ['death']])->assertOk()->assertSee('E 15/1 and 2 km around it');
        $area = $user->watchAreas()->sole();
        $this->assertNotNull($area->confirmed_at);
        $this->assertSame(['death'], $area->alerts());

        // The free plan's one area watch is taken: replacing it needs the person's say-so.
        PegwatchAccountServer::tool(StartWatch::class, ['latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 3])
            ->assertHasErrors(['replace_existing']);
        $this->assertSame([$area->id], $user->watchAreas()->pluck('id')->all());
        PegwatchAccountServer::tool(StartWatch::class, ['latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 3, 'replace_existing' => true])
            ->assertOk()->assertSee('"replaced":"'.$area->name.'"', false);
        $circle = $user->watchAreas()->sole();
        $this->assertSame('circle', $circle->kind);

        PegwatchAccountServer::tool(UpdateWatch::class, ['id' => $circle->id, 'name' => 'Home', 'radius_km' => 12, 'include_covered_deaths' => true])
            ->assertOk()->assertSee('12 km around');
        $circle->refresh();
        $this->assertSame(['Home', 12.0, true], [$circle->name, $circle->radius_km, $circle->include_covered_deaths]);

        PegwatchAccountServer::tool(StopWatch::class, ['id' => $circle->id])->assertOk()->assertSee('"status":"stopped"');
        PegwatchAccountServer::tool(ResumeWatch::class, ['id' => $circle->id])->assertOk()->assertSee('"status":"watching"');
        PegwatchAccountServer::tool(DeleteWatch::class, ['id' => $circle->id])->assertOk()->assertSee('"name":"Home"');
        $this->assertSame(0, $user->watchAreas()->count());
    }

    public function test_an_agent_cannot_touch_someone_elses_watch_or_reshape_a_tenement_watch(): void
    {
        $other = $this->watch($this->user('sam@example.com'));
        $user = $this->user();
        $mine = $this->watch($user, ['kind' => 'tenement', 'tenid' => 'E  1500001', 'latitude' => null, 'longitude' => null, 'radius_km' => null]);
        Passport::actingAs($user, ['mcp:use'], 'api');

        foreach ([StopWatch::class, DeleteWatch::class, UpdateWatch::class] as $tool) {
            PegwatchAccountServer::tool($tool, ['id' => $other->id, 'name' => 'x'])->assertHasErrors(['No watch with that id']);
        }
        $this->assertNull($other->refresh()->unsubscribed_at);
        PegwatchAccountServer::tool(UpdateWatch::class, ['id' => $mine->id, 'radius_km' => 9])->assertHasErrors(['Only circle watches']);
        $this->deleteJson("/api/v1/me/watches/{$other->id}")->assertNotFound();
        $this->assertTrue($other->exists());
    }

    public function test_a_watch_waiting_for_its_email_can_be_confirmed_by_the_agent(): void
    {
        $user = $this->user();
        $old = $this->watch($user);
        $pending = $user->watchAreas()->create(['name' => 'E 15/1', 'kind' => 'tenement', 'tenid' => 'E  1500001', 'event_types' => ['death']]);
        Passport::actingAs($user, ['mcp:use'], 'api');

        PegwatchAccountServer::tool(ListMyWatches::class)->assertOk()->assertSee('waiting_for_confirm');
        PegwatchAccountServer::tool(ConfirmWatch::class, ['id' => $pending->id])->assertHasErrors(['Kalgoorlie']);
        PegwatchAccountServer::tool(ConfirmWatch::class, ['id' => $pending->id, 'replace_existing' => true])->assertOk();
        $this->assertNotNull($pending->refresh()->confirmed_at);
        $this->assertFalse(WatchArea::whereKey($old->id)->exists());
    }

    public function test_the_api_mirrors_the_account_tools(): void
    {
        $user = $this->user();
        Passport::actingAs($user, ['mcp:use'], 'api');

        $id = $this->postJson('/api/v1/me/watches', ['holder' => 'acme gold pty ltd'])->assertCreated()
            ->assertJsonPath('watch.kind', 'holder')->assertJsonPath('watch.status', 'watching')->json('watch.id');
        $this->patchJson("/api/v1/me/watches/$id", ['alerts' => ['release']])->assertOk()->assertJsonPath('watch.alerts', ['release']);
        $this->patchJson("/api/v1/me/watches/$id", ['alerts' => []])->assertUnprocessable();
        $this->postJson("/api/v1/me/watches/$id/stop")->assertOk()->assertJsonPath('watch.status', 'stopped');
        $this->getJson("/api/v1/me/watches/$id")->assertOk()->assertJsonPath('watch.holder', 'ACME GOLD PTY LTD');
        $this->deleteJson("/api/v1/me/watches/$id")->assertNoContent();
        $this->getJson('/api/v1/me/watches')->assertOk()->assertJsonPath('watches', []);
    }

    public function test_settings_list_connected_agents_and_disconnect_them(): void
    {
        $user = $this->user();
        $client = DB::table('oauth_clients')->insertGetId(['id' => $id = (string) Str::uuid(), 'name' => 'Claude', 'redirect_uris' => '[]', 'grant_types' => '[]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('oauth_access_tokens')->insert(['id' => 'tok1', 'user_id' => $user->id, 'client_id' => $id, 'scopes' => '["mcp:use"]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour()]);

        $this->actingAs($user)->get(route('connected-agents.edit'))->assertOk()->assertSee('Claude');
        Livewire::actingAs($user)->test('pages::settings.agents')->call('disconnect', $id)->assertDontSeeHtml('data-test="connected-agent"');
        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', 'tok1')->value('revoked'));
    }
}
