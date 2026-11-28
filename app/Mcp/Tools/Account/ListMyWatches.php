<?php

namespace App\Mcp\Tools\Account;

use App\Agent\Watches;
use App\Alerts\AlertMatcher;
use App\Models\WatchArea;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_my_watches')]
#[Description('The signed-in person\'s watches: areas, tenements and holders they get email alerts on, with what is inside each, which alerts are on, and the last alert emails sent. Ids here are what the other watch tools take.')]
#[IsReadOnly]
#[IsIdempotent]
class ListMyWatches extends AccountTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user($request);

        return $this->result([
            'email' => $user->email,
            'watches' => Watches::list($user),
            'plan' => ['areas' => WatchArea::FREE_LIMIT, 'holders' => WatchArea::FREE_LIMIT, 'alerts' => array_keys(WatchArea::FREE_ALERTS)],
            'next_check' => AlertMatcher::nextCheck(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
