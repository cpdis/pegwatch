<?php

namespace App\Http\Controllers;

use App\Alerts\AlertMatcher;
use App\Support\Register;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Called by the nightly ingest when a run finishes; matches and sends alerts. */
class IngestHookController extends Controller
{
    public function __invoke(Request $request, AlertMatcher $matcher): JsonResponse
    {
        $token = (string) config('services.ingest.token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401);

        $runId = (int) ($request->input('run_id') ?: Register::latestRun()?->id);
        abort_if($runId === 0, 422, 'No ingest run');

        return response()->json(['run_id' => $runId] + $matcher->run($runId));
    }
}
