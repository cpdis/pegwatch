<?php

namespace Tests\Feature;

use App\Mail\DigestConfirmMail;
use App\Mail\WatchConfirmMail;
use App\Mcp\Servers\PegwatchServer;
use App\Mcp\Tools\GetDataStatus;
use App\Mcp\Tools\GetHolder;
use App\Mcp\Tools\GetTenement;
use App\Mcp\Tools\ListActivity;
use App\Mcp\Tools\RequestWatch;
use App\Mcp\Tools\SearchRegister;
use App\Mcp\Tools\TenementsAtPoint;
use App\Models\WatchArea;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Agents as first-class users: discovery files, Markdown pages, the JSON API and the MCP server. */
class AgentSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private static function square(float $x, float $y, float $w = 0.01): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $w);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 20:00', 'Australia/Perth'));
        $run = DB::table('ingest_runs')->insertGetId([
            'status' => 'ok', 'finished_at' => now(), 'stale' => false, 'tenements_extract' => '2026-11-23 04:11:00+08',
        ]);
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, applied_at, granted_at, ends_at, extract_at, geom)
             values ('E  1500001', 'E 15/1', 'EXPLORATION LICENCE', 'E', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', 100, '2020-03-01', '2021-05-04', '2999-12-31', now(), ?::geometry),
                    ('P  1500002', 'P 15/2', 'PROSPECTING LICENCE', 'P', 'PENDING', '{\"SMITH, JOHN\"}', '{f}', 100, '2026-11-02', null, null, now(), ?::geometry)",
            [self::square(121.50, -30.80), self::square(121.60, -30.80)]
        );
        DB::insert(
            "insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders, details, geom)
             values (?, 'application', 'P  1500002', 'P 15/2', 'PROSPECTING LICENCE', '{\"SMITH, JOHN\"}', '{\"area_ha\": 100}', ?::geometry)",
            [$run, self::square(121.60, -30.80)]
        );
    }

    public function test_robots_txt_sets_content_signals_ai_bot_groups_and_the_sitemap(): void
    {
        $body = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->getContent();

        $this->assertStringContainsString("User-agent: *\nContent-Signal: search=yes, ai-input=yes, ai-train=no\nAllow: /", $body);
        $this->assertStringContainsString('Disallow: /watch-areas', $body);
        $this->assertStringContainsString("User-agent: OAI-SearchBot\nUser-agent: ChatGPT-User", $body);
        // Training-only crawlers are told no when ai-train is off.
        $this->assertMatchesRegularExpression('/User-agent: GPTBot\n(User-agent: [^\n]+\n)+Content-Signal: [^\n]+\nDisallow: \/\n/', $body);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $body);
        $this->assertStringContainsString('Agentmap: '.url('/.well-known/ai-catalog.json'), $body);
    }

    public function test_robots_txt_follows_the_training_setting(): void
    {
        config(['pegwatch.agents.ai_train' => true]);

        $body = $this->get('/robots.txt')->getContent();

        $this->assertStringContainsString('ai-train=yes', $body);
        $this->assertDoesNotMatchRegularExpression('/User-agent: GPTBot\n(User-agent: [^\n]+\n)+Content-Signal: [^\n]+\nDisallow: \/\n/', $body);
    }

    public function test_the_sitemaps_list_pages_tenements_and_company_holders_but_not_people(): void
    {
        $index = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->getContent();
        $this->assertStringContainsString(url('/sitemaps/pages.xml'), $index);
        $this->assertStringContainsString(url('/sitemaps/tenements-1.xml'), $index);

        $pages = $this->get('/sitemaps/pages.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>'.url('/activity').'</loc>', $pages);
        $this->assertStringContainsString('<loc>'.url('/agents').'</loc>', $pages);
        $this->assertStringNotContainsString('watch-areas', $pages);
        $this->assertStringNotContainsString('/login', $pages);

        $this->get('/sitemaps/tenements-1.xml')->assertOk()->assertSee(url('/tenements/e-15-1'), false);
        $holders = $this->get('/sitemaps/holders-1.xml')->assertOk()->getContent();
        $this->assertStringContainsString(url('/holders/acme-gold-pty-ltd'), $holders);
        $this->assertStringNotContainsString('smith-john', $holders);

        $this->get('/sitemaps/tenements-9.xml')->assertNotFound();
        $this->get('/sitemaps/nothing.xml')->assertNotFound();
    }

    public function test_llms_txt_and_the_guide(): void
    {
        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringStartsWith('# Pegwatch', $llms);
        $this->assertStringContainsString('1 live and 1 pending tenements', $llms);
        $this->assertStringContainsString(url('/mcp'), $llms);

        $this->get('/llms-full.txt')->assertOk()->assertSee('## Agent guide', false)->assertSee('request_watch', false);
        $this->get('/agents')->assertOk()->assertSee('Pegwatch for agents')->assertSee('tenements_at_point');
    }

    public function test_the_well_known_discovery_documents(): void
    {
        $catalog = $this->get('/.well-known/api-catalog')->assertOk()->assertHeader('Content-Type', 'application/linkset+json');
        $this->assertSame(url('/api/openapi.json'), $catalog->json('linkset.0.service-desc.0.href'));

        $card = $this->get('/.well-known/mcp/server-card.json')->assertOk();
        $this->assertSame('pegwatch', $card->json('serverInfo.name'));
        $this->assertSame(url('/mcp'), $card->json('transport.endpoint'));
        $this->assertCount(12, $card->json('tools'));
        $this->assertContains('request_watch', array_column($card->json('tools'), 'name'));

        $skills = $this->get('/.well-known/agent-skills/index.json')->assertOk();
        $skill = $this->get($skills->json('skills.0.url'))->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
        $this->assertSame('sha256:'.hash('sha256', $skill->getContent()), $skills->json('skills.0.digest'));
        $this->assertStringStartsWith("---\nname: pegwatch\n", $skill->getContent());

        $ai = $this->get('/.well-known/ai-catalog.json')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        foreach ($ai->json('entries') as $entry) {
            $this->assertTrue(isset($entry['url']) xor isset($entry['data']));
            $this->assertStringStartsWith('urn:air:', $entry['identifier']);
        }

        $this->assertMatchesRegularExpression('/^# .*auth\.md/', $this->get('/auth.md')->assertOk()->getContent());
        $this->assertSame('3.1.0', $this->get('/api/openapi.json')->assertOk()->json('openapi'));
    }

    public function test_pages_carry_link_headers_and_vary_on_accept(): void
    {
        $res = $this->get('/tenements/e-15-1')->assertOk()->assertSee('rel="alternate" type="text/markdown"', false);

        $link = $res->headers->get('Link');
        $this->assertStringContainsString('<'.url('/tenements/e-15-1.md').'>; rel="alternate"; type="text/markdown"', $link);
        $this->assertStringContainsString('rel="api-catalog"', $link);
        $this->assertStringContainsString('rel="service-desc"', $link);
        $this->assertStringContainsString('Accept', $res->headers->get('Vary'));
        $this->assertStringContainsString('<'.url('/index.md').'>', $this->get('/')->headers->get('Link'));
    }

    public function test_pages_come_as_markdown_when_asked(): void
    {
        $md = $this->get('/tenements/e-15-1', ['Accept' => 'text/markdown'])->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')->getContent();
        $this->assertStringStartsWith('# E 15/1', $md);
        $this->assertStringContainsString('- Holders: [ACME GOLD PTY LTD]('.url('/holders/acme-gold-pty-ltd').')', $md);
        $this->assertStringContainsString('- Granted: 2021-05-04', $md);

        // The same through the .md path, which keeps its query string out of the way.
        $this->assertSame($md, $this->get('/tenements/e-15-1.md')->assertOk()->getContent());

        $this->get('/index.md')->assertOk()->assertSee('# Pegwatch: WA mining tenement map', false)->assertSee('Pending applications: 1', false);
        $this->get('/holders/acme-gold-pty-ltd.md')->assertOk()->assertSee('# ACME GOLD PTY LTD', false);
        // A natural person's page stays out of search in Markdown too.
        $this->get('/holders/smith-john.md')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        // Any other page is its HTML converted.
        $this->get('/data.md')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=utf-8')->assertSee('Disclaimer');
        $this->get('/tenements/zz-99-999.md')->assertNotFound()->assertSee('# Not found', false);
        // Browsers still get HTML.
        $this->get('/tenements/e-15-1', ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])->assertHeader('Content-Type', 'text/html; charset=utf-8');
        // auth.md is a file of its own, not a page's Markdown.
        $this->get('/auth.md')->assertOk()->assertSee('Reading needs no credentials');
    }

    public function test_the_api_looks_things_up(): void
    {
        $this->getJson('/api/v1/search?q=e 15/1')->assertOk()->assertJsonPath('results.0.fmt_tenid', 'E 15/1')
            ->assertJsonPath('results.0.url', url('/tenements/e-15-1'));

        $t = $this->getJson('/api/v1/tenements/E15%2F1')->assertOk();
        $t->assertJsonPath('tenement.fmt_tenid', 'E 15/1')->assertJsonPath('tenement.applied_at', '2020-03-01')
            ->assertJsonPath('tenement.ends_at', null)->assertJsonMissingPath('geometry');
        $this->assertNotNull($this->getJson('/api/v1/tenements/e-15-1?geometry=1')->json('geometry'));
        $this->getJson('/api/v1/tenements/zz-1')->assertNotFound()->assertJsonStructure(['message']);

        $this->getJson('/api/v1/tenements/at?lat=-30.795&lng=121.505')->assertOk()
            ->assertJsonPath('held', true)->assertJsonPath('tenements.0.fmt_tenid', 'E 15/1');
        $this->getJson('/api/v1/tenements/at?lat=10&lng=121')->assertUnprocessable();

        $this->getJson('/api/v1/tenements?bbox=121.4,-30.9,121.7,-30.7&kinds=p')->assertOk()
            ->assertJsonPath('returned', 1)->assertJsonPath('tenements.0.fmt_tenid', 'P 15/2');
        $this->getJson('/api/v1/tenements?bbox=1,2,3')->assertUnprocessable();

        $this->getJson('/api/v1/holders/ACME GOLD PTY LTD')->assertOk()->assertJsonPath('holder.slug', 'acme-gold-pty-ltd');
        $this->getJson('/api/v1/activity?types=application&limit=1')->assertOk()
            ->assertJsonPath('items.0.fmt_tenid', 'P 15/2')->assertJsonPath('next_offset', null);
        $this->getJson('/api/v1/status')->assertOk()->assertJsonPath('totals.LIVE', 1)
            ->assertJsonPath('latest_run.register_extract_at', '2026-11-23T04:11:00+08:00');
        $this->getJson('/api/v1/watches/preview?tenement=E 15/1')->assertOk()->assertJsonPath('kind', 'tenement');
    }

    public function test_the_api_asks_for_a_watch_and_the_owner_confirms_it(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/watches', ['tenement' => 'E 15/1', 'email' => 'Pat@Example.com'])
            ->assertStatus(202)->assertJsonPath('status', 'confirmation_sent');

        $area = WatchArea::sole();
        $this->assertNull($area->confirmed_at);
        $this->assertSame('tenement', $area->kind);
        Mail::assertSent(WatchConfirmMail::class, fn ($m) => $m->hasTo('pat@example.com'));

        $this->postJson('/api/v1/watches', ['email' => 'pat@example.com'])->assertNotFound();
        $this->postJson('/api/v1/watches', ['tenement' => 'E 15/1', 'email' => 'nope'])->assertUnprocessable();
    }

    public function test_the_api_asks_for_the_digest(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/digest/subscriptions', ['email' => 'pat@example.com'])->assertStatus(202);

        Mail::assertSent(DigestConfirmMail::class);
        $this->assertDatabaseHas('digest_subscribers', ['email' => 'pat@example.com', 'source' => 'agent', 'confirmed_at' => null]);
    }

    public function test_the_mcp_tools_answer_as_the_api_does(): void
    {
        PegwatchServer::tool(SearchRegister::class, ['query' => 'acme'])->assertOk()->assertSee('ACME GOLD PTY LTD');
        PegwatchServer::tool(GetTenement::class, ['id' => 'e 15/1'])->assertOk()->assertSee('E 15/1');
        PegwatchServer::tool(GetTenement::class, ['id' => 'zz 1'])->assertHasErrors();
        PegwatchServer::tool(TenementsAtPoint::class, ['latitude' => -30.795, 'longitude' => 121.505])->assertOk()->assertSee('E 15/1');
        PegwatchServer::tool(TenementsAtPoint::class, ['latitude' => 5, 'longitude' => 121.5])->assertHasErrors();
        PegwatchServer::tool(GetHolder::class, ['holder' => 'acme gold pty ltd'])->assertOk()->assertSee('ACME GOLD PTY LTD');
        PegwatchServer::tool(ListActivity::class, ['types' => ['application']])->assertOk()->assertSee('P 15/2');
        PegwatchServer::tool(GetDataStatus::class)->assertOk()->assertSee('2026-11-23T04:11:00+08:00');
    }

    public function test_the_mcp_watch_tool_only_sends_a_confirm_link(): void
    {
        Mail::fake();

        PegwatchServer::tool(RequestWatch::class, ['latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 5, 'email' => 'pat@example.com'])
            ->assertOk()->assertSee('confirmation_sent');

        $this->assertNull(WatchArea::sole()->confirmed_at);
        Mail::assertSent(WatchConfirmMail::class);
    }

    public function test_the_mcp_server_speaks_streamable_http(): void
    {
        $init = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], ['Accept' => 'application/json, text/event-stream'])->assertOk();
        $this->assertSame('Pegwatch', $init->json('result.serverInfo.name'));

        $list = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['Accept' => 'application/json, text/event-stream', 'MCP-Session-Id' => $init->headers->get('MCP-Session-Id') ?? '', 'MCP-Protocol-Version' => '2025-06-18'])
            ->assertOk();
        $this->assertCount(12, $list->json('result.tools'));
    }
}
