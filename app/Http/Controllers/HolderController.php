<?php

namespace App\Http\Controllers;

use App\Support\Register;
use Inertia\Inertia;
use Inertia\Response;

class HolderController extends Controller
{
    public function show(string $slug): Response
    {
        $data = Register::holder($slug);
        abort_unless($data, 404);

        return Inertia::render('holders/show', $data + ['run' => Register::latestRun()]);
    }
}
