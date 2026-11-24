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

#[Name('find_tenements')]
#[Description('Live and pending tenements in a box, filtered as the map filters them (kind, status, application dates, expiry, size), newest application first, with counts for the box. Use search_register first to find a town\'s centre.')]
#[IsReadOnly]
#[IsIdempotent]
class FindTenements extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, Parse::tenementFilterRules() + ['limit' => ['nullable', 'integer', 'min:1', 'max:'.Lookup::MAX_LIST]]);

        return $this->result(Lookup::tenementsIn(Parse::bbox($v['bbox'] ?? null), Parse::tenementFilter($v), (int) ($v['limit'] ?? 50)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'bbox' => $schema->array()->items($schema->number())->description('west, south, east, north in degrees. Leave out for all of WA.'),
            'kinds' => $schema->array()->items($schema->string()->enum([...Register::KINDS, 'other']))->description('Type codes: E exploration, P prospecting, M mining lease, L miscellaneous, G general purpose, R retention; "other" for the rest.'),
            'statuses' => $schema->array()->items($schema->string()->enum(['LIVE', 'PENDING'])),
            'applied_from' => $schema->string()->format('date'),
            'applied_to' => $schema->string()->format('date'),
            'expires_within_months' => $schema->integer()->min(1)->max(120)->description('Only live tenements expiring within this many months.'),
            'min_ha' => $schema->number()->min(0),
            'max_ha' => $schema->number()->min(0),
            'limit' => $schema->integer()->min(1)->max(Lookup::MAX_LIST)->default(50),
        ];
    }
}
