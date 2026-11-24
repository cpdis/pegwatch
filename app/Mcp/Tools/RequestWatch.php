<?php

namespace App\Mcp\Tools;

use App\Agent\Lookup;
use App\Agent\Parse;
use App\Alerts\WatchRequest;
use App\Models\WatchArea;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('request_watch')]
#[Description('Ask for free email alerts on an area, a tenement or a holder for the person you are helping. This only emails a confirm link to their address: nothing is watched or sent until they press it (within 7 days). Use only an address the person gave you for this. The free plan has one area and one holder watch; confirming replaces the old one.')]
#[IsOpenWorld]
class RequestWatch extends AgentTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $this->input($request, Parse::shapeRules() + [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:80'],
            'alerts' => ['nullable', 'array', 'list', 'min:1'],
            'alerts.*' => [Rule::in(array_keys(WatchArea::FREE_ALERTS))],
            'include_covered_deaths' => ['nullable', 'boolean'],
        ]);
        $shape = Lookup::shape($v);
        if (! $shape) {
            return $this->missing(Parse::SHAPE_MISSING);
        }
        $email = Str::lower(trim($v['email']));
        if (! WatchRequest::emailConfirm($email, $shape, $v['name'] ?? $shape->defaultName(),
            $v['alerts'] ?? array_keys(WatchArea::FREE_ALERTS), (bool) ($v['include_covered_deaths'] ?? false))) {
            return $this->missing('Too many sign-ups from here or for this address. Try again later.');
        }

        return $this->result(['status' => 'confirmation_sent', 'email' => $email, 'covers' => $shape->describe(),
            'next' => 'Tell the person to press the link in the email from Pegwatch within '.WatchArea::CONFIRM_DAYS.' days. No alerts are sent until then.']);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tenement' => $schema->string()->max(40)->description('A tenement ID, e.g. "M 27/518": its outline plus 2 km.'),
            'holder' => $schema->string()->max(200)->description('A holder name: their live and pending tenements.'),
            'latitude' => $schema->number()->min(-36)->max(-13)->description('Centre of a circle.'),
            'longitude' => $schema->number()->min(112)->max(130)->description('Centre of a circle.'),
            'radius_km' => $schema->number()->min(1)->max(50)->default(10),
            'email' => $schema->string()->format('email')->max(254)->description('The person\'s own email address. Only they can confirm.')->required(),
            'name' => $schema->string()->max(80)->description('A name for the watch. Defaults to the nearest town or the tenement or holder.'),
            'alerts' => $schema->array()->items($schema->string()->enum(array_keys(WatchArea::FREE_ALERTS)))->description('"death" (tenements recorded dead) and/or "release" (ground scheduled for release). Defaults to both.'),
            'include_covered_deaths' => $schema->boolean()->default(false)->description('Also alert on deaths whose ground other tenements still cover.'),
        ];
    }
}
