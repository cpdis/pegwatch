<?php

namespace App\Http\Controllers;

use App\Support\Register;
use Inertia\Inertia;
use Inertia\Response;

class TenementController extends Controller
{
    public function show(string $slug): Response
    {
        $data = Register::tenement($slug);
        abort_unless($data, 404);

        return Inertia::render('tenements/show', $data + ['run' => Register::latestRun()]);
    }
}
