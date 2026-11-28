<?php

namespace App\Http\Controllers\Api;

use App\Agent\Lookup;
use App\Agent\Parse;
use App\Agent\Watches;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WatchArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/me: the signed-in person's watches, for an agent holding an OAuth
 * access token from Pegwatch. The same actions as the account MCP tools.
 */
class AccountApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->ok(['email' => $user->email, 'watches' => Watches::list($user)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $area = Watches::find($this->user($request), $id);

        return $area ? $this->ok(['watch' => Watches::present($area)]) : $this->missing();
    }

    public function store(Request $request): JsonResponse
    {
        $v = Parse::validate($request->all(), Parse::shapeRules() + Parse::watchOptionRules() + ['replace_existing' => ['nullable', 'boolean']]);
        $shape = Lookup::shape($v);
        if (! $shape) {
            return response()->json(['message' => Parse::SHAPE_MISSING], 404);
        }

        return $this->ok(Watches::create($this->user($request), $shape, $v['name'] ?? $shape->defaultName(),
            $v['alerts'] ?? array_keys(WatchArea::FREE_ALERTS), (bool) ($v['include_covered_deaths'] ?? false), (bool) ($v['replace_existing'] ?? false)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = Parse::validate($request->all(), Watches::updateRules());
        $area = Watches::find($this->user($request), $id);

        return $area ? $this->ok(['watch' => Watches::update($area, $v)]) : $this->missing();
    }

    public function stop(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (WatchArea $a) => $a->stop());
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, fn (WatchArea $a) => $a->resume());
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $v = Parse::validate($request->all(), ['replace_existing' => ['nullable', 'boolean']]);
        $area = Watches::find($this->user($request), $id);

        return $area ? $this->ok(Watches::confirm($area, (bool) ($v['replace_existing'] ?? false))) : $this->missing();
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $area = Watches::find($this->user($request), $id);
        if (! $area) {
            return $this->missing();
        }
        $area->delete();

        return response()->json(null, 204);
    }

    private function act(Request $request, string $id, callable $action): JsonResponse
    {
        $area = Watches::find($this->user($request), $id);
        if (! $area) {
            return $this->missing();
        }
        $action($area);

        return $this->ok(['watch' => Watches::present($area->refresh(), false)]);
    }

    private function user(Request $request): User
    {
        return $request->user('api');
    }

    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function missing(): JsonResponse
    {
        return response()->json(['message' => Watches::WATCH_MISSING], 404);
    }
}
