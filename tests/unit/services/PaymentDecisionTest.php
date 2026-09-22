<?php
declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\tests\unit\services;

use PHPUnit\Framework\TestCase;
use wmd\banktransferpaymentcodes\services\PaymentDecision;

final class PaymentDecisionTest extends TestCase
{
    public function testDecisions(): void
    {
        self::assertSame('pay', PaymentDecision::decide(24.60, 24.60, 0.0, false));
        self::assertSame('pay', PaymentDecision::decide(24.60, 24.55, 0.10, false));
        self::assertSame('underpaid', PaymentDecision::decide(24.60, 20.00, 0.0, false));
        self::assertSame('overpaid', PaymentDecision::decide(24.60, 30.00, 0.0, false));
        self::assertSame('already_paid', PaymentDecision::decide(0.0, 24.60, 0.0, true));
        self::assertSame('pay', PaymentDecision::decide(24.60, null, 0.0, false)); // no amount given = trust the caller
    }
}
