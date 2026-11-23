<?php

namespace App\Http\Controllers;

use App\Alerts\AlertMatcher;
use App\Digest\WeeklyDigest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Called by the nightly ingest when a run finishes: sends alerts, then the weekly digest when due. */
class IngestHookController extends Controller
{
    public function __invoke(Request $request, AlertMatcher $matcher, WeeklyDigest $digest): JsonResponse
    {
        $token = (string) config('services.ingest.token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401);

        $runId = $request->integer('run_id') ?: null;

        $alerts = $matcher->run($runId);
        TileController::pruneExpired();

        return response()->json(['run_id' => $runId] + $alerts + ['digest' => $digest->run()]);
    }
}
