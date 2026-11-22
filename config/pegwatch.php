<?php

return [
    // ISO weekday (1 = Monday) from which the weekly digest is due. It goes
    // out after the first nightly ingest on or after that day.
    'digest_day' => (int) env('PEGWATCH_DIGEST_DAY', 1),
];
