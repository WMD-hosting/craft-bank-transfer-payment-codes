<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\models;

/**
 * Everything a payment code needs, resolved from an order (or built by hand
 * for an invoice). Immutable.
 */
final class PaymentDetails
{
    public function __construct(
        public readonly BankAccount $account,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $reference,
        public readonly string $referenceModel,
        public readonly bool $referenceIsStructured,
        public readonly string $purpose,
        public readonly string $orderNumber,
        public readonly ?string $payerName = null,
        public readonly ?string $payerStreet = null,
        public readonly ?string $payerCity = null,
        public readonly ?string $payerPostcode = null,
    ) {
    }

    /** Amount in minor units as a string, e.g. 12.34 → "1234". */
    public function cents(): string
    {
        return (string)(int)round($this->amount * 100);
    }
}
