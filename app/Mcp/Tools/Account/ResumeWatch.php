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

#[Name('resume_watch')]
#[Description('Turn alert emails back on for a stopped watch.')]
#[IsIdempotent]
class ResumeWatch extends AccountTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['id' => ['required', 'integer']]);
        $area = $this->watch($request, $v['id']);
        if (! $area) {
            return $this->missing(Watches::WATCH_MISSING);
        }
        $area->resume();

        return $this->result(['watch' => Watches::present($area->refresh(), false)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $this->idSchema($schema)];
    }
}
