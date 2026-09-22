<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\services;

/**
 * Pure decision logic for whether a reported payment should capture the
 * order: no Craft/Commerce dependency, so it is fully unit-testable.
 */
final class PaymentDecision
{
    public static function decide(float $outstanding, ?float $paid, float $tolerance, bool $alreadyPaid): string
    {
        if ($alreadyPaid || $outstanding <= 0.005) {
            return 'already_paid';
        }
        if ($paid === null) {
            return 'pay';
        }
        $diff = round($paid - $outstanding, 2);
        if (abs($diff) <= $tolerance + 0.0001) {
            return 'pay';
        }
        return $diff < 0 ? 'underpaid' : 'overpaid';
    }
}
