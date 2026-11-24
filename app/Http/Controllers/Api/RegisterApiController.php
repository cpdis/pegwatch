<?php

namespace App\Http\Controllers\Api;

use App\Agent\Lookup;
use App\Agent\Parse;
use App\Alerts\WatchRequest;
use App\Digest\DigestSignup;
use App\Http\Controllers\Controller;
use App\Models\WatchArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The public JSON API (/api/v1), described by /api/openapi.json. Read
 * endpoints need no key. The two that send email (a watch, the digest) only
 * send a confirm link: nothing starts until its owner presses it.
 */
class RegisterApiController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), ['q' => ['required', 'string', 'min:2', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:25']]);

        return $this->ok(['query' => $v['q'], 'results' => Lookup::search($v['q'], (int) ($v['limit'] ?? 10))]);
    }

    public function tenement(Request $request, string $id): JsonResponse
    {
        $data = Lookup::tenement($id, $request->boolean('geometry'));

        return $data ? $this->ok($data) : $this->missing("No tenement $id on the register, live, pending or dead.");
    }

    public function tenements(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), Parse::tenementFilterRules() + ['limit' => ['nullable', 'integer', 'min:1', 'max:'.Lookup::MAX_LIST]]);

        return $this->ok(Lookup::tenementsIn(Parse::bbox($v['bbox'] ?? null), Parse::tenementFilter($v), (int) ($v['limit'] ?? 50)));
    }

    public function at(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), Parse::pointRules());

        return $this->ok(Lookup::tenementsAt((float) $v['lng'], (float) $v['lat']));
    }

    public function holder(string $slug): JsonResponse
    {
        $data = Lookup::holder($slug);

        return $data ? $this->ok($data) : $this->missing("No holder $slug on the register.");
    }

    public function activity(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), Parse::activityRules());

        return $this->ok(Lookup::activity(Parse::activityFilter($v), (int) ($v['limit'] ?? 50), (int) ($v['offset'] ?? 0)));
    }

    public function releases(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), ['bbox' => Parse::bboxRule(), 'limit' => ['nullable', 'integer', 'min:1', 'max:'.Lookup::MAX_LIST]]);

        return $this->ok(['releases' => Lookup::releases(Parse::bbox($v['bbox'] ?? null), (int) ($v['limit'] ?? 50))]);
    }

    public function status(): JsonResponse
    {
        return $this->ok(Lookup::status());
    }

    public function digest(?string $week = null): JsonResponse
    {
        $data = Lookup::digest($week);

        return $data ? $this->ok($data) : $this->missing('No digest issue'.($week ? " for $week" : ' yet').'.');
    }

    public function watchPreview(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), Parse::shapeRules());
        $shape = Lookup::shape($v);

        return $shape ? $this->ok(Lookup::watchPreview($shape)) : $this->missing(Parse::SHAPE_MISSING);
    }

    public function watch(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), Parse::shapeRules() + Parse::watchOptionRules() + [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ]);
        $shape = Lookup::shape($v);
        if (! $shape) {
            return $this->missing(Parse::SHAPE_MISSING);
        }
        $email = Str::lower(trim($v['email']));
        $sent = WatchRequest::emailConfirm($email, $shape, $v['name'] ?? $shape->defaultName(),
            $v['alerts'] ?? array_keys(WatchArea::FREE_ALERTS), (bool) ($v['include_covered_deaths'] ?? false));

        return $sent
            ? $this->ok(['status' => 'confirmation_sent', 'email' => $email, 'covers' => $shape->describe(),
                'next' => 'The owner of the address must press the link in the email within '.WatchArea::CONFIRM_DAYS.' days. No alerts are sent until then.'], 202)
            : $this->tooMany();
    }

    public function digestSubscribe(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), ['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $email = Str::lower(trim($v['email']));

        return DigestSignup::request($email, 'agent')
            ? $this->ok(['status' => 'confirmation_sent', 'email' => $email,
                'next' => 'The owner of the address must press the link in the email. Nothing else is sent until then.'], 202)
            : $this->tooMany();
    }

    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function missing(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }

    private function tooMany(): JsonResponse
    {
        return response()->json(['message' => 'Too many sign-ups from here or for this address. Try again later.'], 429);
    }
}
