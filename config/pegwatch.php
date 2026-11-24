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

    // How AI crawlers and agents may use the site's pages (robots.txt).
    // Content Signals (contentsignals.org): search, ai-input (use in answers),
    // ai-train. With ai_train off, training-only crawlers are also disallowed.
    'agents' => [
        'search' => (bool) env('PEGWATCH_AI_SEARCH', true),
        'ai_input' => (bool) env('PEGWATCH_AI_INPUT', true),
        'ai_train' => (bool) env('PEGWATCH_AI_TRAIN', false),
    ],
];
