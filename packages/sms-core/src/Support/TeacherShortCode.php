<?php

declare(strict_types=1);

namespace SmsCore\Support;

/**
 * Derives a teacher's short code from their name.
 *
 * A short code is what a teacher is called on a routine grid and on every
 * RADAR screen that has no room for "Md. Zillur Rahman" — it is not optional,
 * and teachers.short_code is NOT NULL. Every writer that can produce a teacher
 * without one (the demo seeder, and the backfill that made the column NOT NULL)
 * derives it here so they all agree on the shape.
 */
final class TeacherShortCode
{
    /** teachers.short_code is varchar(10). */
    public const MAX_LENGTH = 10;

    /**
     * Honorifics carried by most Bangladeshi staff names. Left in, every
     * teacher's initials would start with the same letter and read as noise.
     *
     * @var array<int, string>
     */
    private const HONORIFICS = [
        'MD', 'MOHD', 'MOHAMMAD', 'MUHAMMAD', 'MOHAMMED',
        'MST', 'MOST', 'MISS', 'MRS', 'MR', 'MS', 'DR', 'PROF',
    ];

    /**
     * Initials for a name: "Md. Zillur Rahman" -> "ZR", "Ayesha" -> "AY".
     *
     * Always returns at least two characters so a code is never a single
     * ambiguous letter, and never more than MAX_LENGTH.
     */
    public static function initials(string $fullName): string
    {
        $words = preg_split('/[^A-Za-z]+/', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map('strtoupper', $words);

        $significant = array_values(array_filter(
            $words,
            static fn (string $w): bool => ! in_array($w, self::HONORIFICS, true),
        ));

        // A name that is nothing BUT honorifics still has to yield something.
        if ($significant === []) {
            $significant = $words;
        }

        $initials = implode('', array_map(
            static fn (string $w): string => $w[0],
            $significant,
        ));

        if ($initials === '') {
            return 'TR';
        }

        if (strlen($initials) === 1) {
            // Pad from the first significant word rather than inventing a
            // letter: "Ayesha" -> "AY", not "AX".
            $initials = strtoupper(substr($significant[0], 0, 2));
            $initials = str_pad($initials, 2, 'X');
        }

        return substr($initials, 0, self::MAX_LENGTH);
    }

    /**
     * A short code for $fullName that does not collide with $taken.
     *
     * short_code is unique per (school_id, short_code), so $taken is the set of
     * codes already used in that school. On collision a numeric suffix is
     * appended, with the base trimmed so the result still fits MAX_LENGTH.
     *
     * @param  array<int, string>  $taken  codes already in use in the school
     */
    public static function unique(string $fullName, array $taken): string
    {
        $base = self::initials($fullName);
        $used = array_flip(array_map('strtoupper', $taken));

        if (! isset($used[$base])) {
            return $base;
        }

        for ($n = 2; $n < 100000; $n++) {
            $suffix = (string) $n;
            $candidate = substr($base, 0, self::MAX_LENGTH - strlen($suffix)).$suffix;

            if (! isset($used[$candidate])) {
                return $candidate;
            }
        }

        throw new \RuntimeException("Cannot derive a unique teacher short code for \"{$fullName}\".");
    }
}
