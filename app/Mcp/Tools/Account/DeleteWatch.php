<?php

namespace App\Mcp\Tools\Account;

use App\Agent\Watches;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_watch')]
#[Description('Delete one of the signed-in person\'s watches and its alert history. This cannot be undone; stop_watch keeps it instead. Ask the person first.')]
#[IsDestructive]
class DeleteWatch extends AccountTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['id' => ['required', 'integer']]);
        $area = $this->watch($request, $v['id']);
        if (! $area) {
            return $this->missing(Watches::WATCH_MISSING);
        }
        $name = $area->name;
        $area->delete();

        return $this->result(['deleted' => ['id' => (int) $v['id'], 'name' => $name]]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $this->idSchema($schema)];
    }
}
