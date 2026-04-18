<?php

namespace App\Services\Pps;

class ConMarksService
{
    // Format A multipliers
    public const SPOT_TEST_MULTIPLIER     = 0.50; // max raw 10 → CON max 5
    public const CLASS_TEST2_MULTIPLIER   = 0.25; // max raw 20 → CON max 5
    public const TERM_CON_T1_MULTIPLIER   = 0.85; // 1st Term → CON max 85
    public const TERM_CON_T2_MULTIPLIER   = 0.80; // 2nd Term → CON max 80
    public const VT_MULTIPLIER            = 0.20; // 2nd Term only, max raw 25 → CON max 5

    // Format B multipliers
    public const CQ_CON_MULTIPLIER        = 0.75; // CQ × 0.75

    /**
     * Compute all CON fields for Format A (term-based) marks.
     * Pass is_second_term=true for 2nd Term rows.
     *
     * @param array{
     *   spot_test?: float|null,
     *   class_test2?: float|null,
     *   attendance?: float|null,
     *   term_marks?: float|null,
     *   vt?: float|null,
     * } $raw
     * @return array{
     *   spot_test_con: float|null,
     *   class_test2_con: float|null,
     *   term_con: float|null,
     *   vt_con: float|null,
     *   total_obtained: float|null,
     * }
     */
    public function computeTermCon(array $raw, bool $isSecondTerm = false): array
    {
        $spotCon   = isset($raw['spot_test'])   ? round($raw['spot_test']   * self::SPOT_TEST_MULTIPLIER, 2)   : null;
        $ct2Con    = isset($raw['class_test2']) ? round($raw['class_test2'] * self::CLASS_TEST2_MULTIPLIER, 2)  : null;
        $termMulti = $isSecondTerm ? self::TERM_CON_T2_MULTIPLIER : self::TERM_CON_T1_MULTIPLIER;
        $termCon   = isset($raw['term_marks'])  ? round($raw['term_marks']  * $termMulti, 2)                    : null;
        $vtCon     = ($isSecondTerm && isset($raw['vt'])) ? round($raw['vt'] * self::VT_MULTIPLIER, 2)          : null;

        $att = $raw['attendance'] ?? null;

        // Total = spot_con + ct2_con + att + term_con [+ vt_con for T2]
        $parts = [$spotCon, $ct2Con, $att, $termCon];
        if ($isSecondTerm) {
            $parts[] = $vtCon;
        }

        $total = null;
        if (count(array_filter($parts, fn ($v) => $v !== null)) > 0) {
            $total = round(array_sum(array_map(fn ($v) => $v ?? 0, $parts)), 2);
        }

        return [
            'spot_test_con'   => $spotCon,
            'class_test2_con' => $ct2Con,
            'term_con'        => $termCon,
            'vt_con'          => $vtCon,
            'total_obtained'  => $total,
        ];
    }

    /**
     * Compute CON fields for Format B (Pre-Test Class 12).
     *
     * @param array{
     *   ct?: float|null,
     *   attendance?: float|null,
     *   cq?: float|null,
     *   mcq?: float|null,
     *   mcq_con_multiplier?: float|null,
     * } $raw
     * @return array{
     *   cq_con: float|null,
     *   mcq_con: float|null,
     *   total_obtained: float|null,
     * }
     */
    public function computePretestCon(array $raw): array
    {
        $cqCon  = isset($raw['cq'])  ? round($raw['cq']  * self::CQ_CON_MULTIPLIER, 2) : null;

        // MCQ CON multiplier is school-configurable (not yet confirmed from data).
        // Default to 1.0 (pass-through) until confirmed. Pass mcq_con_multiplier to override.
        $mcqMultiplier = $raw['mcq_con_multiplier'] ?? 1.0;
        $mcqCon = isset($raw['mcq']) ? round($raw['mcq'] * $mcqMultiplier, 2) : null;

        $ct  = $raw['ct']         ?? null;
        $att = $raw['attendance'] ?? null;

        // Total = ct + att + cq_con + mcq_con
        $parts = [$ct, $att, $cqCon, $mcqCon];
        $total = null;
        if (count(array_filter($parts, fn ($v) => $v !== null)) > 0) {
            $total = round(array_sum(array_map(fn ($v) => $v ?? 0, $parts)), 2);
        }

        return [
            'cq_con'         => $cqCon,
            'mcq_con'        => $mcqCon,
            'total_obtained' => $total,
        ];
    }
}
