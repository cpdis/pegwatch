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

#[Name('get_tenement')]
#[Description('One tenement, live, pending or dead, by its ID in any spelling ("M 27/518", "m27/518", "m-27-518"): type, status, holders, dates, area, its history of changes, tenements over it, scheduled releases, land restrictions, pending actions, where it is and a plain-English summary.')]
#[IsReadOnly]
#[IsIdempotent]
class GetTenement extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['id' => ['required', 'string', 'max:40'], 'include_geometry' => ['nullable', 'boolean']]);
        $data = Lookup::tenement($v['id'], (bool) ($v['include_geometry'] ?? false));

        return $data ? $this->result($data) : $this->missing("No tenement {$v['id']} on the register, live, pending or dead.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->max(40)->description('The tenement ID, e.g. "E 24/250".')->required(),
            'include_geometry' => $schema->boolean()->default(false)->description('Include the outline as GeoJSON (large).'),
        ];
    }
}
