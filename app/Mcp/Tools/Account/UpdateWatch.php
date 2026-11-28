<?php

namespace App\Mcp\Tools\Account;

use App\Agent\Watches;
use App\Models\WatchArea;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_watch')]
#[Description('Change one of the signed-in person\'s watches: rename it, choose its alerts, include deaths whose ground is still covered, or resize or move a circle. Only the fields given change.')]
#[IsIdempotent]
class UpdateWatch extends AccountTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['id' => ['required', 'integer']] + Watches::updateRules());
        $area = $this->watch($request, $v['id']);

        return $area ? $this->result(['watch' => Watches::update($area, $v)]) : $this->missing(Watches::WATCH_MISSING);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchema($schema),
            'name' => $schema->string()->max(80),
            'alerts' => $schema->array()->items($schema->string()->enum(array_keys(WatchArea::FREE_ALERTS)))->description('The alerts to have on, replacing the current set: "death" and/or "release".'),
            'include_covered_deaths' => $schema->boolean(),
            'radius_km' => $schema->number()->min(1)->max(50)->description('Circle watches only.'),
            'latitude' => $schema->number()->min(-36)->max(-13)->description('New centre, circle watches only. Give with longitude.'),
            'longitude' => $schema->number()->min(112)->max(130)->description('New centre, circle watches only. Give with latitude.'),
        ];
    }
}
