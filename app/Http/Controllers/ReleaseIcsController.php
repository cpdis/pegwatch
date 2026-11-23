<?php

namespace App\Http\Controllers;

use App\Support\Ics;
use App\Support\Register;
use Illuminate\Http\Response;

/** A tenement's next compulsory release as a calendar file, so the ballot window is not missed. */
class ReleaseIcsController
{
    public function __invoke(string $slug): Response
    {
        $r = Register::nextRelease($slug);
        abort_unless($r, 404);

        return response(Ics::release($r->fmt_tenid, $r->rlesid, $r->release_local, route('tenements.show', $r->slug)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$r->slug.'-release.ics"',
        ]);
    }
}
