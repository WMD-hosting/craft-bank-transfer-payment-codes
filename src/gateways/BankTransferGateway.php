<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\gateways;

use Craft;
use craft\commerce\gateways\Manual;

/**
 * "Bank transfer" gateway: behaves like Commerce's Manual gateway (the order
 * completes with a pending authorisation; capture = paid) and is the hook the
 * plugin uses to know which orders need a payment reference and codes.
 */
class BankTransferGateway extends Manual
{
    public static function displayName(): string
    {
        return Craft::t('bank-transfer-payment-codes', 'Bank transfer with payment codes');
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('bank-transfer-payment-codes/_gateway-settings.twig', ['gateway' => $this]);
    }
}
