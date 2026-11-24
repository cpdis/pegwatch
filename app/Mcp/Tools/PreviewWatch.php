<?php

namespace App\Mcp\Tools;

use App\Agent\Lookup;
use App\Agent\Parse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('preview_watch')]
#[Description('What a free watch would cover before asking for one: give a tenement ID (its outline plus 2 km), a holder, or a latitude and longitude with a radius (1 to 50 km). Shows the tenements inside now and what changed there in the last 30 days.')]
#[IsReadOnly]
#[IsIdempotent]
class PreviewWatch extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, Parse::shapeRules());
        $shape = Lookup::shape($v);

        return $shape ? $this->result(Lookup::watchPreview($shape)) : $this->missing(Parse::SHAPE_MISSING);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tenement' => $schema->string()->max(40)->description('A tenement ID, e.g. "M 27/518": its outline plus 2 km.'),
            'holder' => $schema->string()->max(200)->description('A holder name: their live and pending tenements.'),
            'latitude' => $schema->number()->min(-36)->max(-13)->description('Centre of a circle.'),
            'longitude' => $schema->number()->min(112)->max(130)->description('Centre of a circle.'),
            'radius_km' => $schema->number()->min(1)->max(50)->default(10),
        ];
    }
}
