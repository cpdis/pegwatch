<?php

namespace App\Http\Controllers;

use App\Support\Register;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $type = $request->query('type');
        if (! is_string($type) || ! array_key_exists($type, Register::EVENT_LABELS)) {
            $type = null;
        }
        $page = min(10000, max(1, (int) $request->integer('page', 1)));
        $perPage = 50;
        $filter = $type ? ['type' => $type] : [];

        return Inertia::render('activity', [
            'events' => Register::events($filter, $perPage + 1, ($page - 1) * $perPage),
            'type' => $type,
            'page' => $page,
            'perPage' => $perPage,
            'labels' => Register::EVENT_LABELS,
            'run' => Register::latestRun(),
        ]);
    }
}
