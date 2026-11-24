<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * How people use Pegwatch: four illustrative walkthroughs and the words they
 * need. The people are composites, and the page says so: presenting them as
 * real customers would be misleading (Australian Consumer Law s18).
 */
new #[Title('How people use Pegwatch')] class extends Component {
    /**
     * Each story: who, what they want, the steps (a step marked pro is on the
     * Prospector plan) and what Pegwatch cannot do for them.
     */
    public const STORIES = [
        [
            'id' => 'prospector',
            'name' => 'A prospector pegging dead ground',
            'who' => 'A weekend prospector and detectorist in Kalgoorlie, with a Miner\'s Right and two prospecting licences.',
            'wants' => 'To hear the night good ground near him is given up, before anyone else pegs it.',
            'steps' => [
                ['Watch an area', 'He searches for Kurnalpi on the map, draws a 30 km circle and confirms it from his email. No password. Every night after the register changes inside it, he gets an email.', false],
                ['A tenement dies', 'An alert says a prospecting licence 11 km from his own was recorded dead, reason Expired, and that 84% of its ground has no other live or pending tenement over it. Deaths whose ground is still covered by another tenement are left out, so he only reads the ones worth a drive.', false],
                ['Check the ground', 'The tenement page shows what is over that ground now, who held it before, and any land restrictions. He turns on freed ground and the one-minute block grid to see how the patch sits against the exploration licences around it.', false],
                ['Confirm before pegging', 'He opens TENGRAPH from the page to check nothing has been lodged since last night, and sends the prewritten enquiry to the Kalgoorlie Mining Registrar.', false],
                ['Peg it', 'He marks out the corners on the ground and lodges his application with the Registrar. Pegwatch shows it as pending once the register has it.', false],
                ['Catch a release', 'Part of a big exploration licence next door is due for compulsory release at 8:30am on a set day. He adds it to his calendar and gets reminders the day and the hour before.', false],
                ['Take it into the field', 'Outlines exported as GPX or KML for his GPS or detector app, and alerts for new applications near his ground.', true],
            ],
            'limits' => 'No dataset shows pegs in the ground, and the register is a day or more behind. Someone may already have marked out ground that looks free.',
        ],
        [
            'id' => 'tenement-manager',
            'name' => 'A tenement manager at a junior explorer',
            'who' => 'The tenement manager for a small listed explorer with 42 tenements around Leonora: exploration licences, a few prospecting licences and a mining lease application.',
            'wants' => 'To know who is pegging around her projects, and never be surprised on her own ground.',
            'steps' => [
                ['Watch her own company', 'From her company\'s holder page she watches the holder, so any of its tenements recorded dead or scheduled for release reaches her inbox.', false],
                ['Watch the project', 'A circle over the Leonora project, and a watch on the flagship licence and 2 km around it.', false],
                ['Read a competitor', 'A rival\'s holder page shows how much ground they hold, their applications and deaths per month for two years, and their recent changes. Similar names are listed as a name match, not a corporate group.', false],
                ['Brief the board on a grant', 'Her mining lease has waited 14 months. The page shows the median time to grant for that type in her mineral field since 2020, a better answer than a guess.', false],
                ['Hear about new pegging the night it lands', 'Alerts for new applications, grants, transfers, partial surrenders and expiry near her ground.', true],
            ],
            'limits' => 'Her own compliance dates (rent, expenditure, the annual report) stay in her tenement management system.',
        ],
        [
            'id' => 'investor',
            'name' => 'An investor watching companies\' ground',
            'who' => 'A private investor in WA small-cap explorers who writes a newsletter about them.',
            'wants' => 'To see what companies are doing on the ground, which often shows on the register before it shows in a report.',
            'steps' => [
                ['Read the Monday digest', 'The week\'s deaths ranked by freed ground, the biggest new applications and who lodged them, and the releases due in the next seven days. Each issue has a public page he can link to.', false],
                ['Follow a company', 'He watches one company\'s holder page. Three new applications after a quiet year is worth a question to the company.', false],
                ['Filter the activity feed', 'Every change in his mineral fields, newest first, including applications from the department\'s Mining Notices that the register does not have yet. He follows the filtered view by RSS.', false],
                ['Export a company\'s holdings', 'A CSV of a holder\'s tenements for his own spreadsheet.', true],
            ],
            'limits' => 'Pegwatch matches holder names, not corporate groups: subsidiaries with other names and joint ventures need the company\'s own reports. It reports the register and makes no recommendation about any company.',
        ],
        [
            'id' => 'consultant',
            'name' => 'A tenement consultant',
            'who' => 'A two-person tenement services business in Kalgoorlie, lodging and managing ground for about 30 prospectors and small syndicates.',
            'wants' => 'Never miss a release or a death in a client\'s area, and tell clients where they would stand.',
            'steps' => [
                ['Run release mornings', 'The map\'s Releases tab lists every scheduled release with its exact time. Applications lodged in the first 15 minutes go to ballot, so she has her clients\' applications drafted the night before.', false],
                ['Read a contested patch', 'The tenement page lists the tenements over the ground in lodgement order from the register, so she can tell a client they would be third in line. A pending application does not close ground.', false],
                ['Explain what does not block', 'Miscellaneous licences, general purpose leases and special prospecting licences can overlap other titles, so Pegwatch leaves them out of its freed-ground sums.', false],
                ['Check the data is current', 'The data page shows the latest register extract and every nightly check, which answers "is this up to date?" before she acts.', false],
                ['Watch every client', 'Unlimited areas and holder watches, and field exports for clients heading out to mark out.', true],
            ],
            'limits' => 'Private land, townsites and reserves can need consent before marking out, and they are not in the open data Pegwatch reads. She checks land tenure separately.',
        ],
    ];

    /** Term => what it means, for the words the stories use. */
    public const TERMS = [
        'Tenement' => 'Any title granted under the Mining Act 1978: a prospecting licence (P), exploration licence (E), mining lease (M), miscellaneous licence (L), general purpose lease (G) or retention licence (R).',
        'Pegging, marking out' => 'Marking a prospecting licence or mining lease boundary on the ground with posts, then lodging the application. It can give priority, and no dataset shows it.',
        'Live, pending, dead' => 'Granted and in force; applied for and not yet decided; ended.',
        'Recorded dead' => 'A tenement that the register shows as surrendered, withdrawn, expired, forfeited or refused. Most of that ground is still covered by another tenement.',
        'Freed ground' => 'Part of a recent death with no live or pending tenement over it on the register. A screening aid, not a sign the ground can be applied for.',
        'Compulsory release' => 'Part of an exploration licence the holder must give up after a set number of years. It opens for applications at a set time, usually 8:30am AWST.',
        'Ballot' => 'Applications lodged in the first 15 minutes after a release are treated as lodged at once, and a draw decides priority.',
        'Graticular block' => 'A one-minute by one-minute cell of latitude and longitude, about 3 km², the unit exploration licences are applied for in.',
        'Forfeiture' => 'Cancelling a tenement, for example after a successful plaint in the Warden\'s Court that its holder did not meet the conditions.',
        'Section 19, Section 57' => 'Land the Mining Act closes to mining or restricts. Pegwatch shows the department\'s layers for both, with file notation areas.',
        'File notation area' => 'A note on the register that another agency has an interest in the land. A warning, not a ban.',
        'TENGRAPH' => 'The department\'s official online tenement map, and the place to confirm anything Pegwatch shows.',
        'Mining Registrar' => 'The official at each regional mining registry who receives applications and answers tenement enquiries.',
    ];
}; ?>

<div class="max-w-3xl">
    <x-eyebrow>{{ __('Examples') }}</x-eyebrow>
    <flux:heading size="xl" level="1" class="mt-2">{{ __('How people use Pegwatch') }}</flux:heading>
    <flux:text class="mt-3 text-base">
        {{ __('Four examples of how a prospector, a tenement manager, an investor and a consultant might use Pegwatch day to day. The people are illustrative composites, not real customers. Steps marked Prospector need the paid plan; everything else is free.') }}
    </flux:text>

    <nav class="mt-5 flex flex-wrap gap-2" aria-label="{{ __('Examples') }}">
        @foreach ($this::STORIES as $story)
            <flux:button size="sm" variant="subtle" :href="'#'.$story['id']">{{ __($story['name']) }}</flux:button>
        @endforeach
    </nav>

    @foreach ($this::STORIES as $story)
        <section id="{{ $story['id'] }}" class="mt-10 scroll-mt-20" data-test="use-case">
            <flux:heading size="lg" level="2">{{ __($story['name']) }}</flux:heading>
            <flux:text class="mt-2">{{ __($story['who']) }}</flux:text>
            <flux:text class="mt-1"><span class="font-semibold text-zinc-900 dark:text-white">{{ __('What they want:') }}</span> {{ __($story['wants']) }}</flux:text>

            <ol class="mt-4 space-y-3 border-s border-zinc-200 ps-5 text-sm dark:border-zinc-700">
                @foreach ($story['steps'] as [$title, $text, $pro])
                    <li>
                        <p class="flex flex-wrap items-center gap-2 font-semibold text-zinc-900 dark:text-white">
                            {{ __($title) }}
                            @if ($pro)<x-pro-lock ref="use-case-{{ $story['id'] }}">{{ __('Prospector') }}</x-pro-lock>@endif
                        </p>
                        <p class="mt-0.5 text-zinc-600 dark:text-zinc-300">{{ __($text) }}</p>
                    </li>
                @endforeach
            </ol>

            <flux:text class="mt-4 text-sm"><span class="font-semibold text-zinc-900 dark:text-white">{{ __('What Pegwatch cannot do here:') }}</span> {{ __($story['limits']) }}</flux:text>
        </section>
    @endforeach

    <flux:heading size="lg" level="2" class="mt-12">{{ __('The words in these examples') }}</flux:heading>
    <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[11rem_minmax(0,1fr)]" data-test="terms">
        @foreach ($this::TERMS as $term => $meaning)
            <dt class="font-semibold text-zinc-900 dark:text-white">{{ __($term) }}</dt>
            <dd class="text-zinc-600 dark:text-zinc-300 max-sm:-mt-2">{{ __($meaning) }}</dd>
        @endforeach
    </dl>

    <div class="mt-10 flex flex-wrap gap-2">
        <flux:button variant="primary" icon="bell-alert" :href="route('watch.start')" wire:navigate>{{ __('Watch an area for free') }}</flux:button>
        <flux:button :href="route('pro')" wire:navigate>{{ __('See the plans') }}</flux:button>
    </div>

    <flux:text class="mt-6 text-xs">
        {{ __('A screening aid, not advice. Before you peg, apply, buy or sell, confirm in') }}
        <flux:link href="{{ \App\Support\Format::TENGRAPH_URL }}" target="_blank" rel="noreferrer">TENGRAPH</flux:link>
        {{ __('and with the') }}
        <flux:link href="{{ \App\Support\Format::MINING_REGISTRAR_URL }}" target="_blank" rel="noreferrer">{{ __('Mining Registrar') }}</flux:link>.
    </flux:text>
</div>
