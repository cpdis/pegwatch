<?php

namespace App\Http\Controllers;

use App\Support\Register;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('map', [
            'run' => Register::latestRun(),
            'totals' => Register::totals(),
            'events' => Register::events([], 40),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        return response()->json(Register::search(is_string($q = $request->query('q')) ? $q : ''));
    }
}
