<?php

namespace App\Mcp\Tools\Account;

use App\Agent\Lookup;
use App\Agent\Parse;
use App\Agent\Watches;
use App\Models\WatchArea;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('start_watch')]
#[Description('Start email alerts now for the signed-in person on an area (a circle), a tenement (its outline plus 2 km) or a holder. Give exactly one of tenement, holder, or latitude and longitude. The free plan has one area watch and one holder watch: if one of that kind is already on, this fails and names it, and replace_existing: true deletes it. Ask the person before replacing.')]
class StartWatch extends AccountTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, Parse::shapeRules() + Parse::watchOptionRules() + [
            'replace_existing' => ['nullable', 'boolean'],
        ]);
        $shape = Lookup::shape($v);
        if (! $shape) {
            return $this->missing(Parse::SHAPE_MISSING);
        }

        return $this->result(Watches::create($this->user($request), $shape, $v['name'] ?? $shape->defaultName(),
            $v['alerts'] ?? array_keys(WatchArea::FREE_ALERTS), (bool) ($v['include_covered_deaths'] ?? false), (bool) ($v['replace_existing'] ?? false)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tenement' => $schema->string()->max(40)->description('A tenement ID, e.g. "M 27/518": its outline plus 2 km.'),
            'holder' => $schema->string()->max(200)->description('A holder name: their live and pending tenements.'),
            'latitude' => $schema->number()->min(-36)->max(-13)->description('Centre of a circle.'),
            'longitude' => $schema->number()->min(112)->max(130)->description('Centre of a circle.'),
            'radius_km' => $schema->number()->min(1)->max(50)->default(10),
            'name' => $schema->string()->max(80)->description('A name for the watch. Defaults to the nearest town or the tenement or holder.'),
            'alerts' => $schema->array()->items($schema->string()->enum(array_keys(WatchArea::FREE_ALERTS)))->description('"death" (tenements recorded dead) and/or "release" (ground scheduled for release). Defaults to both.'),
            'include_covered_deaths' => $schema->boolean()->default(false)->description('Also alert on deaths whose ground other tenements still cover.'),
            'replace_existing' => $schema->boolean()->default(false)->description('Delete the person\'s current watch of this kind to make room. Only after they agree.'),
        ];
    }
}
