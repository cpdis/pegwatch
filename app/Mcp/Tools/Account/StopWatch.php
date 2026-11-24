<?php

namespace App\Mcp\Tools\Account;

use App\Agent\Watches;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('stop_watch')]
#[Description('Stop alert emails for one of the signed-in person\'s watches. Unsent alerts are dropped; the watch is kept and resume_watch turns it back on.')]
#[IsIdempotent]
class StopWatch extends AccountTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['id' => ['required', 'integer']]);
        $area = $this->watch($request, $v['id']);
        if (! $area) {
            return $this->missing(Watches::WATCH_MISSING);
        }
        $area->stop();

        return $this->result(['watch' => Watches::present($area->refresh(), false)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $this->idSchema($schema)];
    }
}
