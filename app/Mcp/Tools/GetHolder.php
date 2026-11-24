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

#[Name('get_holder')]
#[Description('A tenement holder\'s portfolio by name or page slug: live and pending tenements with a summary by type, dead tenements, applications and deaths by month, companies with matching names, and recent changes. Natural persons are named as the register names them and nothing more.')]
#[IsReadOnly]
#[IsIdempotent]
class GetHolder extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['holder' => ['required', 'string', 'max:200']]);
        $data = Lookup::holder($v['holder']);

        return $data ? $this->result($data) : $this->missing("No holder {$v['holder']} on the register. Try search_register.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'holder' => $schema->string()->max(200)->description('The holder\'s name as on the register, e.g. "NORTHERN STAR RESOURCES LIMITED".')->required(),
        ];
    }
}
