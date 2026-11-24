<?php

namespace App\Mcp\Tools;

use App\Agent\Lookup;
use App\Agent\Parse;
use App\Support\Register;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_activity')]
#[Description('The register\'s change feed, newest first: applications, grants, deaths, holder changes, area changes and releases, filtered by type, tenement kind, mineral field, holder, AWST dates or a box. Pages with `offset`; `next_offset` is null on the last page.')]
#[IsReadOnly]
#[IsIdempotent]
class ListActivity extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, Parse::activityRules());

        return $this->result(Lookup::activity(Parse::activityFilter($v), (int) ($v['limit'] ?? 50), (int) ($v['offset'] ?? 0)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'types' => $schema->array()->items($schema->string()->enum(array_keys(Register::EVENT_LABELS))),
            'kinds' => $schema->array()->items($schema->string()->enum([...Register::KINDS, 'other'])),
            'field' => $schema->string()->description('A mineral field number, e.g. "24" (Kalgoorlie).'),
            'holder' => $schema->string()->max(100)->description('A holder name.'),
            'from' => $schema->string()->format('date')->description('AWST date, inclusive.'),
            'to' => $schema->string()->format('date')->description('AWST date, inclusive.'),
            'bbox' => $schema->array()->items($schema->number())->description('west, south, east, north in degrees.'),
            'limit' => $schema->integer()->min(1)->max(Lookup::MAX_LIST)->default(50),
            'offset' => $schema->integer()->min(0)->default(0),
        ];
    }
}
