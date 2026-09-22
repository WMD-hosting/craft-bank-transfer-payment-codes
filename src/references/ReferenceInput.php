<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

/**
 * What a scheme needs to build a reference. Digits are taken from the order
 * number only when that number is entirely digits; anything else (a hex
 * reference, an "ORD-" prefix, a slug) would lose characters to a digit
 * filter and could collide with another order, so the order ID is used
 * instead. The ID is unique and stable, so the same order always
 * regenerates the same reference.
 */
final class ReferenceInput
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly int $orderId,
    ) {
    }

    public function digits(): string
    {
        return $this->orderNumber !== '' && ctype_digit($this->orderNumber)
            ? $this->orderNumber
            : $this->fallbackDigits();
    }

    /** The collision-free fallback: the order ID, which is unique per order. */
    public function fallbackDigits(): string
    {
        return (string)$this->orderId;
    }
}
