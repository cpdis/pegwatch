{{-- Sign-in pages: the pitch and a real alert on the left (wide screens), the form on the right. --}}
@props(['title' => null])
@php($preview = \App\Alerts\AlertPreview::sample())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-900">
        <div class="grid min-h-dvh lg:grid-cols-2">
            <aside class="hidden flex-col justify-between gap-10 bg-ink p-10 text-white lg:flex xl:p-14">
                <a href="{{ route('home') }}" class="flex items-center gap-2 text-lg font-semibold" wire:navigate>
                    <x-app-logo-icon class="size-7 fill-current text-accent" />
                    {{ __('Pegwatch') }}
                </a>

                <div class="max-w-lg">
                    <p class="text-[11px] font-semibold tracking-[0.08em] text-accent uppercase">{{ __('Free alerts for WA mining tenements') }}</p>
                    <h2 class="mt-3 text-4xl leading-[1.05] font-black tracking-tight xl:text-5xl">{{ __('Know when ground near you changes.') }}</h2>
                    <p class="mt-4 max-w-md text-zinc-300">{{ __('Circle an area, or pick a tenement or a holder. When a tenement there is recorded dead or ground is scheduled for release, we email you after that night\'s register check.') }}</p>

                    <figure class="mt-8 overflow-hidden rounded-xl bg-white text-zinc-900 shadow-2xl ring-1 ring-white/10" aria-label="{{ __('An example alert email') }}">
                        <div class="border-b border-zinc-200 px-5 py-3 text-xs text-zinc-500">
                            <div><span class="font-medium text-zinc-700">{{ __('From') }}</span> Pegwatch</div>
                            <div class="mt-0.5 truncate"><span class="font-medium text-zinc-700">{{ __('Subject') }}</span> {{ $preview['subject'] }}</div>
                        </div>
                        <div class="space-y-4 px-5 py-4 text-sm">
                            @foreach ($preview['items'] as $item)
                                <div>
                                    <div><span class="font-semibold tabular-nums">{{ $item['id'] }}</span> <span class="text-zinc-500">· {{ $item['meta'] }}</span></div>
                                    <p class="mt-0.5 text-zinc-700">{{ $item['line'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </figure>
                    <figcaption class="mt-2 text-xs text-zinc-500">{{ __('Built from the latest changes on the register.') }}</figcaption>
                </div>

                <p class="text-xs text-zinc-500">{{ __('A screening aid, not advice. Confirm in TENGRAPH and with the Mining Registrar.') }}</p>
            </aside>

            <main class="flex flex-col items-center justify-center px-4 py-10 sm:px-8">
                <div class="w-full max-w-sm">
                    <a href="{{ route('home') }}" class="mb-8 flex items-center gap-2 font-semibold lg:hidden" wire:navigate>
                        <span class="flex size-8 items-center justify-center rounded-md bg-ink">
                            <x-app-logo-icon class="size-5 fill-current text-accent" />
                        </span>
                        {{ __('Pegwatch') }}
                    </a>
                    {{ $slot }}
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
