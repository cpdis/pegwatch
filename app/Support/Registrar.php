<?php

namespace App\Support;

/**
 * The regional Mining Registrar offices, and a prewritten enquiry email to one
 * of them about a tenement's ground.
 */
class Registrar
{
    /**
     * Office => email, from the department's office list (checked 24 Nov 2026):
     * https://www.wa.gov.au/organisation/department-of-mines-petroleum-and-exploration/department-of-mines-petroleum-and-exploration-office-locations
     * Marble Bar and Meekatharra are closed; their registrars sit in Perth and Geraldton.
     */
    public const OFFICES = [
        'Kalgoorlie' => 'mineraltitles.kalgoorlie@dmpe.wa.gov.au',
        'Karratha' => 'mineraltitles.karratha@dmpe.wa.gov.au',
        'Leonora' => 'mineraltitles.leonora@dmpe.wa.gov.au',
        'Marble Bar (in Perth)' => 'mineraltitles.marblebar@dmpe.wa.gov.au',
        'Meekatharra (in Geraldton)' => 'mineraltitles.meekatharra@dmpe.wa.gov.au',
        'Mount Magnet' => 'mineraltitles.mtmagnet@dmpe.wa.gov.au',
        'Southern Cross' => 'mineraltitles.southerncross@dmpe.wa.gov.au',
    ];

    public static function subject(string $fmtTenid): string
    {
        return "Enquiry about the ground at $fmtTenid";
    }

    /** The enquiry's text. Names no holder: the question is about the ground. */
    public static function body(string $fmtTenid, string $type, string $status, ?string $location, string $pageUrl): string
    {
        $where = $location ? " ($location)" : '';

        return implode("\n", [
            'Hello,',
            '',
            "I am looking at the ground covered by $fmtTenid, ".lcfirst($type).", $status on the register$where.",
            '',
            'Before I mark out or apply, could you please confirm:',
            "- the current status of $fmtTenid and of any tenement over or next to this ground",
            '- whether any application has been lodged over this ground that is not yet shown in TENGRAPH',
            '- any land restrictions or consents that apply (for example a reserve, townsite, Commonwealth land, private land, Section 19 or Section 57)',
            '',
            "The tenement on Pegwatch: $pageUrl",
            '',
            'Thank you,',
            '',
        ]);
    }

    /** @return array<string, string> office => mailto URL */
    public static function mailtos(string $fmtTenid, string $type, string $status, ?string $location, string $pageUrl): array
    {
        $query = 'subject='.rawurlencode(self::subject($fmtTenid))
            .'&body='.rawurlencode(self::body($fmtTenid, $type, $status, $location, $pageUrl));

        return array_map(fn (string $email) => "mailto:$email?$query", self::OFFICES);
    }
}
