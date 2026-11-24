<?php

namespace App\Mcp\Tools;

use App\Agent\Lookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_digest_issue')]
#[Description('A weekly digest issue: ground left open by dead tenements, the biggest new applications and their applicants, and the releases due in the next week. The latest unless a week is given.')]
#[IsReadOnly]
#[IsIdempotent]
class GetDigestIssue extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['week' => ['nullable', 'string', 'regex:/^[0-9]{4}-W[0-9]{2}$/']]);
        $data = Lookup::digest($v['week'] ?? null);

        return $data ? $this->result($data) : $this->missing('No digest issue'.(isset($v['week']) ? " for {$v['week']}" : ' yet').'.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'week' => $schema->string()->pattern('^[0-9]{4}-W[0-9]{2}$')->description('ISO week, e.g. "2026-W47". Leave out for the latest.'),
        ];
    }
}
