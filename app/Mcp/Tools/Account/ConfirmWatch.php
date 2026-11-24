<?php

namespace App\Mcp\Tools\Account;

use App\Agent\Watches;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('confirm_watch')]
#[Description('Confirm a watch that is waiting for its emailed link, so its alerts start. If it would replace the person\'s current watch of its kind on the free plan, this fails and names it; replace_existing: true goes ahead. Ask the person first.')]
class ConfirmWatch extends AccountTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['id' => ['required', 'integer'], 'replace_existing' => ['nullable', 'boolean']]);
        $area = $this->watch($request, $v['id']);

        return $area ? $this->result(Watches::confirm($area, (bool) ($v['replace_existing'] ?? false))) : $this->missing(Watches::WATCH_MISSING);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $this->idSchema($schema), 'replace_existing' => $schema->boolean()->default(false)];
    }
}
