<?php

namespace App\Http\Controllers;

use App\Agent\OpenApi;
use App\Agent\Sitemap;
use App\Mcp\Servers\PegwatchServer;
use App\Support\PageMeta;
use App\Support\Register;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The files that tell crawlers and agents what Pegwatch is and how to use
 * it: robots.txt, the sitemaps, llms.txt, the API description, and the
 * /.well-known discovery documents (RFC 9727 API catalog, MCP server card,
 * Agent Skills index, AI catalog). All public and cookie-free, so a CDN can
 * cache them.
 */
class DiscoveryController extends Controller
{
    /** Paths no crawler needs: per-person pages, emailed links, hooks, tiles and Livewire's endpoints. */
    public const PRIVATE_PATHS = [
        '/watch-areas', '/watch/confirm/', '/dashboard', '/settings', '/login', '/register', '/forgot-password',
        '/reset-password', '/two-factor-challenge', '/user/', '/email/', '/confirm-password',
        '/digest/confirm/', '/digest/unsubscribe/', '/hooks/', '/livewire', '/tiles/', '/og/areas/',
    ];

    /** AI search and user-triggered fetchers: they put pages in answers, so they follow `search` and `ai_input`. */
    private const AI_ANSWER_BOTS = ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User'];

    /** Crawlers that only gather training data: blocked when ai_train is off. */
    private const AI_TRAINING_BOTS = ['GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Bytespider', 'Amazonbot', 'meta-externalagent'];

    public function robots(): Response
    {
        $c = config('pegwatch.agents');
        $yes = fn (bool $v) => $v ? 'yes' : 'no';
        $signal = 'Content-Signal: search='.$yes($c['search']).', ai-input='.$yes($c['ai_input']).', ai-train='.$yes($c['ai_train']);
        $disallow = implode("\n", array_map(fn ($p) => "Disallow: $p", self::PRIVATE_PATHS));
        $group = fn (array $agents, string $rules) => implode("\n", array_map(fn ($a) => "User-agent: $a", $agents))."\n".$rules;
        $answers = $c['ai_input'] ? "$signal\nAllow: /\n$disallow" : "$signal\nDisallow: /";
        $training = $c['ai_train'] ? "$signal\nAllow: /\n$disallow" : "$signal\nDisallow: /";

        $body = implode("\n\n", [
            '# '.PageMeta::SITE_NAME.': Western Australia\'s mining tenement register, checked nightly.'
                ."\n# Agents: read ".url('/llms.txt').' or use the MCP server at '.url('/mcp').'.'
                ."\n# Content Signals (https://contentsignals.org): search = search indexes, ai-input = use in AI answers, ai-train = model training.",
            $group(['*'], "$signal\nAllow: /\n$disallow"),
            $group(self::AI_ANSWER_BOTS, $answers),
            $group(self::AI_TRAINING_BOTS, $training),
            'Sitemap: '.route('sitemap')."\nAgentmap: ".route('wellknown.ai-catalog'),
        ])."\n";

        return $this->text($body, 'text/plain; charset=utf-8');
    }

    public function sitemapIndex(): Response
    {
        return $this->xml(Sitemap::index());
    }

    public function sitemap(string $part): Response
    {
        $xml = Sitemap::part($part);
        abort_if($xml === null, 404);

        return $this->xml($xml);
    }

    /** llms.txt (llmstxt.org): what the site is, today's numbers, and where to go next. */
    public function llms(): Response
    {
        return $this->text($this->llmsText(), 'text/plain; charset=utf-8');
    }

    /** llms.txt with the whole agent guide inlined. */
    public function llmsFull(): Response
    {
        return $this->text($this->llmsText()."\n## Agent guide\n\n".view('agent.guide')->render(), 'text/plain; charset=utf-8');
    }

    public function openapi(): JsonResponse
    {
        return $this->json(OpenApi::spec(), 'application/vnd.oai.openapi+json');
    }

    /** RFC 9727 API catalog: the JSON API and the MCP server, each with its description and docs. */
    public function apiCatalog(): JsonResponse
    {
        return $this->json(['linkset' => [
            [
                'anchor' => url('/api/v1'),
                'service-desc' => [['href' => route('api.openapi'), 'type' => 'application/vnd.oai.openapi+json']],
                'service-doc' => [['href' => route('agents'), 'type' => 'text/html']],
                'status' => [['href' => url('/up')]],
            ],
            [
                'anchor' => url('/mcp'),
                'service-desc' => [['href' => route('wellknown.mcp-card'), 'type' => 'application/json']],
                'service-doc' => [['href' => route('agents'), 'type' => 'text/html']],
            ],
        ]], 'application/linkset+json');
    }

    /** MCP Server Card (SEP-1649): how to reach the server and what it offers, before connecting. */
    public function mcpServerCard(): JsonResponse
    {
        return $this->json([
            '$schema' => 'https://static.modelcontextprotocol.io/schemas/mcp-server-card/v1.json',
            'version' => '1.0',
            'protocolVersion' => PegwatchServer::PROTOCOL_VERSION,
            'serverInfo' => ['name' => 'pegwatch', 'title' => PageMeta::SITE_NAME, 'version' => PegwatchServer::VERSION],
            'description' => 'Western Australia\'s mining tenement register, checked nightly: search, tenement and holder lookup, '
                .'what is held at a point, the change feed, compulsory releases, and free email alerts (confirmed by email).',
            'iconUrl' => asset('icon.png'),
            'documentationUrl' => route('agents'),
            'transport' => ['type' => 'streamable-http', 'endpoint' => url('/mcp')],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'authentication' => ['required' => false],
            'instructions' => PegwatchServer::INSTRUCTIONS,
            'tools' => PegwatchServer::toolCards(),
        ]);
    }

    /** Agent Skills discovery (v0.2.0): one skill, how to use Pegwatch. */
    public function skillsIndex(): JsonResponse
    {
        return $this->json([
            '$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
            'skills' => [[
                'name' => 'pegwatch',
                'type' => 'skill-md',
                'description' => self::SKILL_DESCRIPTION,
                'url' => route('wellknown.skill', 'pegwatch'),
                'digest' => 'sha256:'.hash('sha256', $this->skillText()),
            ]],
        ]);
    }

    public function skill(string $name): Response
    {
        abort_unless($name === 'pegwatch', 404);

        return $this->text($this->skillText(), 'text/markdown; charset=utf-8');
    }

    /** ARD / ai-catalog: one manifest pointing at the MCP server, the skill and the API. */
    public function aiCatalog(): JsonResponse
    {
        $host = parse_url(url('/'), PHP_URL_HOST);

        return $this->json([
            'specVersion' => '1.0',
            'host' => ['displayName' => PageMeta::SITE_NAME, 'identifier' => 'did:web:'.$host, 'documentationUrl' => route('agents')],
            'entries' => [
                [
                    'identifier' => "urn:air:$host:server:pegwatch",
                    'displayName' => PageMeta::SITE_NAME.' MCP server',
                    'type' => 'application/mcp-server-card+json',
                    'url' => route('wellknown.mcp-card'),
                    'representativeQueries' => self::QUERIES,
                ],
                [
                    'identifier' => "urn:air:$host:skill:pegwatch",
                    'displayName' => 'Using '.PageMeta::SITE_NAME,
                    'type' => 'application/agent-skill+md',
                    'url' => route('wellknown.skill', 'pegwatch'),
                    'representativeQueries' => array_slice(self::QUERIES, 0, 3),
                ],
                [
                    'identifier' => "urn:air:$host:api:register",
                    'displayName' => PageMeta::SITE_NAME.' API',
                    'type' => 'application/vnd.oai.openapi+json',
                    'url' => route('api.openapi'),
                    'representativeQueries' => array_slice(self::QUERIES, 1, 3),
                ],
            ],
        ]);
    }

    /** auth.md: what needs no credentials, and how sign-ups are approved. */
    public function authMd(): Response
    {
        return $this->text(view('agent.auth')->render(), 'text/markdown; charset=utf-8');
    }

    public const SKILL_DESCRIPTION = 'Look up Western Australian mining tenements, holders, recent register changes and upcoming compulsory releases on Pegwatch, and ask for free email alerts. Use for questions about who holds ground in WA, tenement status, applications, deaths and releases.';

    private const QUERIES = [
        'who holds the mining tenement at -30.75, 121.47',
        'is M 27/518 still live and when does it expire',
        'what has Northern Star applied for this month',
        'which tenements near Leonora are scheduled for compulsory release',
        'alert me when ground near Kalgoorlie is released',
    ];

    private function skillText(): string
    {
        return "---\nname: pegwatch\ndescription: ".self::SKILL_DESCRIPTION."\n---\n\n# Pegwatch\n\n".view('agent.guide')->render();
    }

    private function llmsText(): string
    {
        return Cache::remember('llms-txt:'.(Register::latestRun()?->id ?? 0), now()->addHour(), function () {
            $totals = Register::totals();
            $run = Register::latestRun();
            $base = rtrim(url('/'), '/');

            return view('agent.llms', [
                'base' => $base,
                'live' => $totals['LIVE'] ?? 0,
                'pending' => $totals['PENDING'] ?? 0,
                'checked' => $run?->finished_at,
            ])->render();
        });
    }

    private function text(string $body, string $type): Response
    {
        return response($body, 200, ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*']);
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function json(array $data, string $type = 'application/json'): JsonResponse
    {
        return response()->json($data, 200, ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
