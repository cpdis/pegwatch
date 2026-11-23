{{-- The Pegwatch mark: a survey peg with hi-vis tape. One drawing, public/favicon.svg, inlined. --}}
{!! str_replace('<svg ', '<svg aria-hidden="true" focusable="false" '.$attributes->class('shrink-0').' ', trim(file_get_contents(public_path('favicon.svg')))) !!}
