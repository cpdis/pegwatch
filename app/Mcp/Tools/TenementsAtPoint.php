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

#[Name('tenements_at_point')]
#[Description('Who holds the ground at a point in Western Australia: live and pending tenements over it, tenements that died there recently, compulsory releases due there, and the nearest town.')]
#[IsReadOnly]
#[IsIdempotent]
class TenementsAtPoint extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['latitude' => ['required', 'numeric', 'between:-36,-13'], 'longitude' => ['required', 'numeric', 'between:112,130']]);

        return $this->result(Lookup::tenementsAt((float) $v['longitude'], (float) $v['latitude']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'latitude' => $schema->number()->min(-36)->max(-13)->description('WGS84 latitude, e.g. -30.75.')->required(),
            'longitude' => $schema->number()->min(112)->max(130)->description('WGS84 longitude, e.g. 121.47.')->required(),
        ];
    }
}
