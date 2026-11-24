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

#[Name('list_upcoming_releases')]
#[Description('Compulsory releases (ground a holder must give up, which then opens for application) still to come, soonest first, optionally inside a box. Each has a calendar (.ics) link.')]
#[IsReadOnly]
#[IsIdempotent]
class ListUpcomingReleases extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, ['bbox' => Parse::bboxRule(), 'limit' => ['nullable', 'integer', 'min:1', 'max:'.Lookup::MAX_LIST]]);

        return $this->result(['releases' => Lookup::releases(Parse::bbox($v['bbox'] ?? null), (int) ($v['limit'] ?? 50))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'bbox' => $schema->array()->items($schema->number())->description('west, south, east, north in degrees.'),
            'limit' => $schema->integer()->min(1)->max(Lookup::MAX_LIST)->default(50),
        ];
    }
}
