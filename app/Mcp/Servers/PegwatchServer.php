<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Illuminate\Support\Facades\App;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * Pegwatch's MCP server at /mcp (routes/ai.php): the same lookups as the
 * site and the JSON API, as tools. No credentials; the two sign-up tools
 * only email a confirm link to the person.
 */
#[Name('Pegwatch')]
#[Version(self::VERSION)]
#[Instructions(self::INSTRUCTIONS)]
class PegwatchServer extends Server
{
    public const VERSION = '1.0.0';

    public const PROTOCOL_VERSION = ProtocolVersion::V2025_06_18->value;

    public const LOOKUP_INSTRUCTIONS = <<<'MD'
        Pegwatch tracks every live, pending and dead mining tenement in Western Australia, checked against the state register every night.
        Start with search_register for an ID, holder or town, or tenements_at_point for a location. Tenement IDs are type, mineral field and number ("E 24/250"); any spelling works.
        Register dates are Perth (AWST) dates. The register is never live: call get_data_status before saying anything is current.
        Lodgement order is from the register; priority under the Mining Act can depend on marking out, so tell people to confirm with the Mining Registrar before pegging or applying.
        Natural-person holders are named as on the register and nothing more; do not look for their addresses.
        request_watch and request_digest_subscription only email a confirm link to the address given; use an address the person gave you, and tell them to press the link.
        Every result has a `url` to the page a person would open. Data © State of Western Australia (DMPE), CC BY 4.0. Not legal advice.
        MD;

    public const INSTRUCTIONS = self::LOOKUP_INSTRUCTIONS."\n".'To list or change the person\'s own watches, connect to /mcp/account on this host instead: the person signs you in with OAuth.';

    /** @var list<class-string<Tool>> */
    protected array $tools = [
        Tools\SearchRegister::class,
        Tools\GetTenement::class,
        Tools\TenementsAtPoint::class,
        Tools\FindTenements::class,
        Tools\GetHolder::class,
        Tools\ListActivity::class,
        Tools\ListUpcomingReleases::class,
        Tools\GetDataStatus::class,
        Tools\GetDigestIssue::class,
        Tools\PreviewWatch::class,
        Tools\RequestWatch::class,
        Tools\RequestDigestSubscription::class,
    ];

    protected array $capabilities = [
        self::CAPABILITY_TOOLS => ['listChanged' => false],
    ];

    /** The tools as tools/list describes them, for the server card. */
    public static function toolCards(): array
    {
        return self::cardsFor(self::class);
    }

    /** @param class-string<Server> $server */
    protected static function cardsFor(string $server): array
    {
        return array_map(fn (string $class) => array_intersect_key(App::make($class)->toArray(), array_flip(['name', 'title', 'description', 'inputSchema', 'annotations'])),
            (new \ReflectionClass($server))->getDefaultProperties()['tools']);
    }
}
