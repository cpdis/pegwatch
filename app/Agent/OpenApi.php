<?php

namespace App\Agent;

use App\Models\WatchArea;
use App\Support\PageMeta;
use App\Support\Register;

/** The OpenAPI 3.1 description of /api/v1, served at /api/openapi.json. */
final class OpenApi
{
    public static function spec(): array
    {
        $list = fn (string $desc, ?array $enum = null) => [
            'description' => $desc.' An array, or a comma-separated string.',
            'schema' => ['oneOf' => [
                ['type' => 'string'],
                ['type' => 'array', 'items' => array_filter(['type' => 'string', 'enum' => $enum])],
            ]],
        ];
        $q = fn (string $name, array $p) => ['name' => $name, 'in' => 'query', 'required' => false] + $p;
        $bbox = $q('bbox', ['description' => 'A box: west,south,east,north in WGS84 degrees.', 'schema' => ['type' => 'string', 'example' => '121.2,-31.0,121.7,-30.6']]);
        $limit = fn (int $max, int $default) => $q('limit', ['schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'default' => $default]]);
        $kinds = $q('kinds', $list('Tenement kinds by type code; "other" is every other code.', [...Register::KINDS, 'other']));
        $ok = fn (string $desc) => ['200' => ['description' => $desc, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]];
        $notFound = ['404' => ['description' => 'Not on the register.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]]];
        $shape = [
            $q('tenement', ['description' => 'A tenement ID in any spelling ("M 27/518"): its outline plus 2 km.', 'schema' => ['type' => 'string']]),
            $q('holder', ['description' => 'A holder\'s name or page slug: their live and pending tenements.', 'schema' => ['type' => 'string']]),
            $q('latitude', ['schema' => ['type' => 'number']]),
            $q('longitude', ['schema' => ['type' => 'number']]),
            $q('radius_km', ['schema' => ['type' => 'number', 'minimum' => 1, 'maximum' => 50, 'default' => 10]]),
        ];
        $shapeBody = [
            'tenement' => ['type' => 'string'], 'holder' => ['type' => 'string'],
            'latitude' => ['type' => 'number'], 'longitude' => ['type' => 'number'],
            'radius_km' => ['type' => 'number', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
        ];
        $sent = ['202' => ['description' => 'Confirm email sent. Nothing starts until its owner presses the link.', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            '429' => ['description' => 'Too many sign-ups from this client or for this address.']];

        // The signed-in person's watches (OAuth). Operation ids match the account MCP tools.
        $me = ['security' => [['oauth' => ['mcp:use']]]];
        $unauthorised = ['401' => ['description' => 'No or expired access token. Start OAuth from the WWW-Authenticate header.']];
        $watchId = ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'From list_my_watches.', 'schema' => ['type' => 'integer']];
        $noWatch = ['404' => ['description' => 'No watch with that id on this account.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]]];
        $options = [
            'name' => ['type' => 'string', 'maxLength' => 80],
            'alerts' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(WatchArea::FREE_ALERTS)]],
            'include_covered_deaths' => ['type' => 'boolean', 'default' => false],
        ];
        $replace = ['replace_existing' => ['type' => 'boolean', 'default' => false, 'description' => 'Delete the person\'s current watch of this kind (the free plan has one area and one holder watch). Only after they agree.']];
        $body = fn (array $props) => ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $props]]]];
        $taken = ['422' => ['description' => 'Invalid input, or the free plan\'s watch of this kind is taken and replace_existing was not set.']];
        $act = fn (string $id, string $summary) => ['post' => $me + ['operationId' => $id, 'summary' => $summary, 'parameters' => [$watchId],
            'responses' => $ok('The watch.') + $noWatch + $unauthorised]];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => PageMeta::SITE_NAME.' API',
                'version' => '1.0.0',
                'summary' => 'Western Australia\'s mining tenement register, checked nightly, with change history and free alerts.',
                'description' => 'Read endpoints need no key and are rate limited per client (120 a minute). Register dates are Perth (AWST) dates; '
                    .'change times are ISO 8601 with +08:00. Natural-person holders are named as on the public register and nothing more. '
                    .'Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0. Not legal advice. Guide: '.route('agents'),
            ],
            'servers' => [['url' => rtrim(url('/api/v1'), '/')]],
            'externalDocs' => ['url' => route('agents')],
            'paths' => [
                '/search' => ['get' => ['operationId' => 'search_register', 'summary' => 'Search tenements, holders, company groups and towns',
                    'parameters' => [['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100]], $limit(25, 10)],
                    'responses' => $ok('Ranked results; `kind` is tenement, holder, group or place.')]],
                '/tenements/{id}' => ['get' => ['operationId' => 'get_tenement', 'summary' => 'One tenement, live, pending or dead',
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Any spelling: "M 27/518", "m27/518", "m-27-518".', 'schema' => ['type' => 'string']],
                        $q('geometry', ['description' => 'Include the outline as GeoJSON.', 'schema' => ['type' => 'boolean', 'default' => false]]),
                    ],
                    'responses' => $ok('The register record, history, overlaps, releases, restrictions and pending actions.') + $notFound]],
                '/tenements/at' => ['get' => ['operationId' => 'tenements_at_point', 'summary' => 'Who holds the ground at a point',
                    'parameters' => [
                        ['name' => 'lat', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'number', 'minimum' => -36, 'maximum' => -13]],
                        ['name' => 'lng', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'number', 'minimum' => 112, 'maximum' => 130]],
                    ],
                    'responses' => $ok('Live and pending tenements over the point, recent deaths there, releases due there and the nearest place.')]],
                '/tenements' => ['get' => ['operationId' => 'find_tenements', 'summary' => 'Live and pending tenements in a box, under the map\'s filters',
                    'parameters' => [$bbox, $kinds,
                        $q('statuses', $list('LIVE and/or PENDING.', ['LIVE', 'PENDING'])),
                        $q('applied_from', ['schema' => ['type' => 'string', 'format' => 'date']]),
                        $q('applied_to', ['schema' => ['type' => 'string', 'format' => 'date']]),
                        $q('expires_within_months', ['description' => 'Live tenements expiring within this many months.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 120]]),
                        $q('min_ha', ['schema' => ['type' => 'number']]), $q('max_ha', ['schema' => ['type' => 'number']]),
                        $limit(Lookup::MAX_LIST, 50)],
                    'responses' => $ok('Counts for the box and the newest applications first.')]],
                '/holders/{holder}' => ['get' => ['operationId' => 'get_holder', 'summary' => 'A holder\'s portfolio',
                    'parameters' => [['name' => 'holder', 'in' => 'path', 'required' => true, 'description' => 'A name as on the register, or its page slug.', 'schema' => ['type' => 'string']]],
                    'responses' => $ok('Live, pending and dead tenements, activity by month, companies with matching names.') + $notFound]],
                '/activity' => ['get' => ['operationId' => 'list_activity', 'summary' => 'The change feed, newest first',
                    'parameters' => [
                        $q('types', $list('Event types.', array_keys(Register::EVENT_LABELS))), $kinds,
                        $q('field', ['description' => 'A mineral field number ("24").', 'schema' => ['type' => 'string']]),
                        $q('holder', ['description' => 'A holder name.', 'schema' => ['type' => 'string']]),
                        $q('from', ['schema' => ['type' => 'string', 'format' => 'date']]), $q('to', ['schema' => ['type' => 'string', 'format' => 'date']]),
                        $bbox, $limit(Lookup::MAX_LIST, 50), $q('offset', ['schema' => ['type' => 'integer', 'minimum' => 0, 'default' => 0]]),
                    ],
                    'responses' => $ok('`items` and `next_offset` (null on the last page).')]],
                '/releases' => ['get' => ['operationId' => 'list_upcoming_releases', 'summary' => 'Compulsory releases still to come',
                    'parameters' => [$bbox, $limit(Lookup::MAX_LIST, 50)], 'responses' => $ok('Soonest first, each with an .ics calendar link.')]],
                '/status' => ['get' => ['operationId' => 'get_data_status', 'summary' => 'How fresh the data is', 'responses' => $ok('The last check, its register extract and totals.')]],
                '/digest/latest' => ['get' => ['operationId' => 'get_digest_issue', 'summary' => 'The latest weekly digest', 'responses' => $ok('The issue\'s content.') + $notFound]],
                '/digest/{week}' => ['get' => ['operationId' => 'get_digest_issue_by_week', 'summary' => 'A weekly digest issue',
                    'parameters' => [['name' => 'week', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[0-9]{4}-W[0-9]{2}$']]],
                    'responses' => $ok('The issue\'s content.') + $notFound]],
                '/watches/preview' => ['get' => ['operationId' => 'preview_watch', 'summary' => 'What a watch would cover',
                    'parameters' => $shape, 'responses' => $ok('What is inside now and what changed there in the last 30 days.') + $notFound]],
                '/watches' => ['post' => ['operationId' => 'request_watch', 'summary' => 'Ask for free email alerts (sends a confirm link)',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['email'], 'properties' => $shapeBody + [
                        'email' => ['type' => 'string', 'format' => 'email', 'description' => 'The person\'s own address. Only they can confirm.'],
                        'name' => ['type' => 'string', 'maxLength' => 80],
                        'alerts' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(WatchArea::FREE_ALERTS)]],
                        'include_covered_deaths' => ['type' => 'boolean', 'default' => false],
                    ]]]]],
                    'responses' => $sent + $notFound]],
                '/digest/subscriptions' => ['post' => ['operationId' => 'request_digest_subscription', 'summary' => 'Ask for the weekly digest (sends a confirm link)',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['email'], 'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                    ]]]]],
                    'responses' => $sent]],
                '/me/watches' => [
                    'get' => $me + ['operationId' => 'list_my_watches', 'summary' => 'The signed-in person\'s watches', 'responses' => $ok('Each watch with what is inside, its alerts and recent alert emails.') + $unauthorised],
                    'post' => $me + ['operationId' => 'start_watch', 'summary' => 'Start a watch now (no confirm email)',
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $shapeBody + $options + $replace]]]],
                        'responses' => ['201' => ['description' => 'The watch, watching, and the name of any watch it replaced.']] + $taken + $notFound + $unauthorised],
                ],
                '/me/watches/{id}' => [
                    'get' => $me + ['operationId' => 'get_my_watch', 'summary' => 'One of the person\'s watches', 'parameters' => [$watchId], 'responses' => $ok('The watch.') + $noWatch + $unauthorised],
                    'patch' => $me + ['operationId' => 'update_watch', 'summary' => 'Rename a watch, change its alerts, or resize or move a circle', 'parameters' => [$watchId],
                        'requestBody' => $body($options + ['radius_km' => ['type' => 'number', 'minimum' => 1, 'maximum' => 50], 'latitude' => ['type' => 'number'], 'longitude' => ['type' => 'number']]),
                        'responses' => $ok('The watch.') + $taken + $noWatch + $unauthorised],
                    'delete' => $me + ['operationId' => 'delete_watch', 'summary' => 'Delete a watch (cannot be undone)', 'parameters' => [$watchId],
                        'responses' => ['204' => ['description' => 'Deleted.']] + $noWatch + $unauthorised],
                ],
                '/me/watches/{id}/stop' => $act('stop_watch', 'Stop a watch\'s alert emails (kept; resume turns them back on)'),
                '/me/watches/{id}/resume' => $act('resume_watch', 'Turn a stopped watch\'s alerts back on'),
                '/me/watches/{id}/confirm' => ['post' => $me + ['operationId' => 'confirm_watch', 'summary' => 'Confirm a watch waiting for its emailed link', 'parameters' => [$watchId],
                    'requestBody' => $body($replace), 'responses' => $ok('The watch, and the name of any watch it replaced.') + $taken + $noWatch + $unauthorised]],
            ],
            'components' => [
                'schemas' => ['Message' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]],
                'securitySchemes' => ['oauth' => [
                    'type' => 'oauth2',
                    'description' => 'Authorization code with PKCE (S256). Register a client at the registration endpoint first (RFC 7591). The person approves the agent on Pegwatch and can disconnect it in Settings.',
                    'flows' => ['authorizationCode' => [
                        'authorizationUrl' => route('passport.authorizations.authorize'),
                        'tokenUrl' => route('passport.token'),
                        'refreshUrl' => route('passport.token'),
                        'scopes' => ['mcp:use' => 'See and change the person\'s watches.'],
                    ]],
                ]],
            ],
        ];
    }
}
