<?php

return [
    // ISO weekday (1 = Monday) from which the weekly digest is due. It goes
    // out after the first nightly ingest on or after that day.
    'digest_day' => (int) env('PEGWATCH_DIGEST_DAY', 1),

    // What the pre-sale page shows. The charge itself is the Stripe price.
    'presale' => [
        'price_label' => env('PEGWATCH_PRESALE_PRICE_LABEL', 'A$89'),
        'goal' => (int) env('PEGWATCH_PRESALE_GOAL', 20),
    ],

    // An XYZ raster tile URL ({z}/{x}/{y}) and its credit line for the map's
    // satellite toggle. The toggle is hidden while the URL is unset.
    'satellite' => [
        'url' => env('PEGWATCH_SATELLITE_URL'),
        'attribution' => env('PEGWATCH_SATELLITE_ATTRIBUTION'),
    ],
];
