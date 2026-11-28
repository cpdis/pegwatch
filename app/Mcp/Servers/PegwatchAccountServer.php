<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * /mcp/account (routes/ai.php): every public tool, plus the signed-in
 * person's watches. Needs an OAuth access token from Pegwatch; an agent
 * without one gets a 401 that points at the OAuth metadata.
 */
#[Name('Pegwatch (your account)')]
#[Version(PegwatchServer::VERSION)]
#[Instructions(self::INSTRUCTIONS)]
class PegwatchAccountServer extends PegwatchServer
{
    public const INSTRUCTIONS = PegwatchServer::LOOKUP_INSTRUCTIONS."\n".<<<'MD'
        You are signed in as a Pegwatch user. list_my_watches shows their watches; start_watch, update_watch, stop_watch, resume_watch, confirm_watch and delete_watch change them, and alert emails go to that user's own address.
        The free plan has one area watch and one holder watch. Starting or confirming a second of a kind fails and names the one it would replace: ask the person before passing replace_existing. Ask before delete_watch too; stop_watch keeps the watch.
        MD;

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
        Tools\Account\ListMyWatches::class,
        Tools\Account\StartWatch::class,
        Tools\Account\UpdateWatch::class,
        Tools\Account\StopWatch::class,
        Tools\Account\ResumeWatch::class,
        Tools\Account\ConfirmWatch::class,
        Tools\Account\DeleteWatch::class,
    ];

    /** The tools as tools/list describes them, for its server card. */
    public static function toolCards(): array
    {
        return self::cardsFor(self::class);
    }
}
