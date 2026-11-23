{{-- The mark and the name. The name is a constant, so a lower-case APP_NAME never shows. --}}
<a {{ $attributes->class('flex shrink-0 items-center gap-2.5 rounded-md text-zinc-950 dark:text-white') }} data-flux-brand>
    <x-app-logo-icon class="size-8" />
    <span class="text-[17px] leading-none font-bold tracking-[-0.02em]">{{ \App\Support\PageMeta::SITE_NAME }}</span>
</a>
