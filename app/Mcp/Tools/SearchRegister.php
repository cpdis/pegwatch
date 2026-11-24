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

#[Name('search_register')]
#[Description('Search Western Australia\'s mining register: tenements by ID ("E 24/250", "m27/518"), holders by name, groups of companies with matching names ("Northern Star"), and towns. Results are ranked as the site\'s search ranks them; each has a `kind` and a `url`.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchRegister extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['query' => ['required', 'string', 'min:2', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:25']]);

        return $this->result(['query' => $v['query'], 'results' => Lookup::search($v['query'], (int) ($v['limit'] ?? 10))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(2)->max(100)->description('A tenement ID, holder name or town.')->required(),
            'limit' => $schema->integer()->min(1)->max(25)->default(10),
        ];
    }
}
