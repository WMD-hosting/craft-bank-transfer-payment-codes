<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\references;

/**
 * What a scheme needs to build a reference. Digits are taken from the order
 * number; when it has none, the order ID is used.
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
        $digits = preg_replace('/\D+/', '', $this->orderNumber) ?? '';
        return $digits !== '' ? $digits : (string)$this->orderId;
    }
}
