<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\models;

use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;

/**
 * Outcome of a {@see \wmd\banktransferpaymentcodes\services\Payments::markPaid()}
 * call. `status` is one of: paid, already_paid, underpaid, overpaid, not_found,
 * wrong_gateway, no_transaction, capture_failed.
 */
final class MarkPaidResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly ?Order $order = null,
        public readonly ?Transaction $transaction = null,
        public readonly string $message = '',
    ) {
    }
}
