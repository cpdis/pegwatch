<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Title, description, canonical URL and share image for the page being
 * served, derived from its route so page components need not set them.
 * The head partial renders these as meta, Open Graph and Twitter tags.
 */
final class PageMeta
{
    /** The product's name, whatever APP_NAME says. */
    public const SITE_NAME = 'Pegwatch';

    public const DEFAULT_DESCRIPTION = 'A map of every live, pending and dead mining tenement in Western Australia, with free email alerts when a tenement near you is recorded dead or has ground scheduled for release.';

    private function __construct(
        public readonly string $title,
        public readonly string $shareTitle,
        public readonly string $description,
        public readonly string $canonical,
        public readonly ?string $image,
        public readonly ?string $imageAlt,
    ) {}

    public static function for(Request $request, ?string $title = null): self
    {
        $route = $request->route();
        $default = new self(
            filled($title) ? $title.' · '.self::SITE_NAME : self::SITE_NAME.': WA mining tenement map',
            filled($title) ? $title : self::SITE_NAME,
            self::DEFAULT_DESCRIPTION,
            $request->url(),
            asset('og.png'),
            'The Pegwatch mark, a letter P whose stem is a survey peg',
        );
        $slug = $route?->parameter('slug');

        return match ($route?->getName()) {
            'tenements.show' => self::tenement($default, (string) $slug),
            'holders.show' => self::holder($default, (string) $slug),
            'digest' => $default->with(description: 'The week on the WA mining register, every Monday: ground left open by dead tenements, the biggest new applications and the releases coming up.'),
            'digest.issue' => self::digestIssue($default, (string) $route->parameter('week')),
            default => $default,
        };
    }

    private static function tenement(self $default, string $slug): self
    {
        $record = Register::tenementRecord($slug);
        if (! $record) {
            return $default;
        }
        $t = $record['tenement'];
        $id = $t['fmt_tenid'];
        $status = Format::STATUS_LABELS[Format::statusKey($t['status'])];

        return $default->with(
            shareTitle: $id.' · '.TenementSummary::typeName($t).' · '.$status,
            // The same sentence the page opens with (T1).
            description: TenementSummary::headlineText(['tenement' => $t]),
            canonical: route('tenements.show', $t['slug']),
            image: Route::has('og.tenement')
                ? route('og.tenement', ['slug' => $t['slug'], 'v' => Register::latestRun()?->id ?? 0])
                : null,
            imageAlt: "The outline of $id",
        );
    }

    private static function holder(self $default, string $slug): self
    {
        $h = Register::holderBrief($slug);
        if (! $h) {
            return $default;
        }
        $live = (int) $h->live;
        $pending = (int) $h->pending;
        $holds = $live + $pending === 0
            ? 'No live or pending tenements on the WA register.'
            : number_format($live).' live and '.number_format($pending).' pending '.($live + $pending === 1 ? 'tenement' : 'tenements')
                .' on the WA register, '.number_format((float) $h->area_ha).' ha mapped.';

        return $default->with(
            description: $h->name.': '.$holds,
            canonical: route('holders.show', $slug),
            // A natural person's page carries no share image (it stays out of previews and search).
            image: $h->is_company ? $default->image : null,
            imageAlt: $h->is_company ? $default->imageAlt : null,
        );
    }

    private static function digestIssue(self $default, string $week): self
    {
        $issue = DB::table('digest_issues')->where('week', $week)->first();
        if (! $issue) {
            return $default;
        }
        $c = json_decode($issue->content, true);
        $n = fn (string $type) => (int) ($c['counts'][$type] ?? 0);
        $from = Format::awst($c['period_start'], false);
        $to = Format::awst($c['period_end'], false);

        return $default->with(description: "The WA mining register, $from to $to: "
            .trans_choice('{0} no new applications|{1} 1 new application|[2,*] :n new applications', $n('application'), ['n' => $n('application')]).', '
            .trans_choice('{0} no deaths|{1} 1 tenement dead|[2,*] :n tenements dead', $n('death'), ['n' => $n('death')]).', '
            .trans_choice('{0} no releases scheduled|{1} 1 release scheduled|[2,*] :n releases scheduled', $n('release_scheduled'), ['n' => $n('release_scheduled')]).'.');
    }

    private function with(
        ?string $shareTitle = null,
        ?string $description = null,
        ?string $canonical = null,
        ?string $image = '',
        ?string $imageAlt = '',
    ): self {
        return new self(
            $this->title,
            $shareTitle ?? $this->shareTitle,
            $description ?? $this->description,
            $canonical ?? $this->canonical,
            $image === '' ? $this->image : $image,
            $imageAlt === '' ? $this->imageAlt : $imageAlt,
        );
    }
}
