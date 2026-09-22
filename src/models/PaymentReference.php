<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\models;

use wmd\banktransferpaymentcodes\records\PaymentReferenceRecord;

/**
 * The payment reference stored for one order: generated once and reused for
 * every code, statement, and reconciliation lookup after that.
 */
final class PaymentReference
{
    public int $orderId;
    public string $accountKey;
    public string $scheme;
    public string $model;
    public string $reference;
    public bool $isStructured;

    public static function fromRecord(PaymentReferenceRecord $r): self
    {
        $ref = new self();
        $ref->orderId = (int)$r->orderId;
        $ref->accountKey = (string)$r->accountKey;
        $ref->scheme = (string)$r->scheme;
        $ref->model = (string)$r->model;
        $ref->reference = (string)$r->reference;
        $ref->isStructured = (bool)$r->isStructured;
        return $ref;
    }
}
