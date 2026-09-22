<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\variables;

use Craft;
use craft\commerce\elements\Order;
use craft\elements\Asset;
use wmd\banktransferpaymentcodes\controllers\CodeController;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\models\PaymentCode;
use wmd\banktransferpaymentcodes\models\PaymentDetails;
use wmd\banktransferpaymentcodes\models\PaymentReference;
use wmd\banktransferpaymentcodes\Plugin;
use wmd\banktransferpaymentcodes\services\EmailEmbedder;

/**
 * `craft.bankTransferPaymentCodes` and `craft.btpc` in templates.
 */
class BtpcVariable
{
    /** @return PaymentCode[] best code first; empty when the order has no bank account or nothing can render */
    public function codes(Order $order, ?string $accountKey = null): array
    {
        $plugin = Plugin::getInstance();
        $account = $accountKey !== null ? $plugin->accounts->get($accountKey) : null;
        return $plugin->codes->forOrder($order, $account);
    }

    /**
     * @return PaymentCode[] for hand-built details (an invoice, a deposit); see README.
     *     When `details.number` is given, the email source is pre-filled with the same
     *     `cid:` convention {@see \wmd\banktransferpaymentcodes\services\Codes::forOrder()}
     *     uses, so embedding the PNG under that filename (see the README's email snippet)
     *     is enough to make it show in a mail client. Without `number` (or in hosted
     *     email mode, which needs a real order for its signed URL), `emailSrc()` falls
     *     back to a data: URI, which is fine for the web and for PDF but most mail
     *     clients block it, so call `setEmailSrc()` yourself for that case.
     */
    public function codesFor(array $details): array
    {
        $plugin = Plugin::getInstance();
        $account = $plugin->accounts->get((string)($details['account'] ?? '')) ?? $plugin->accounts->default();
        if ($account === null) {
            return [];
        }
        $scheme = $plugin->referenceSchemes->forAccount($account);
        $reference = (string)($details['reference'] ?? '');
        $number = (string)($details['number'] ?? '');
        $d = new PaymentDetails($account, (float)$details['amount'], (string)($details['currency'] ?? 'EUR'), $reference, $scheme->model() ?? '', (bool)($details['structured'] ?? true), (string)($details['purpose'] ?? ''), $number);
        $codes = $plugin->codes->forDetails($d);
        if ($number !== '') {
            foreach ($codes as $code) {
                $code->setEmailSrc('cid:' . EmailEmbedder::cidName($number, $code->handle()));
            }
        }
        return $codes;
    }

    public function details(Order $order): ?PaymentDetails
    {
        return Plugin::getInstance()->codes->detailsFor($order);
    }

    public function reference(Order $order): ?PaymentReference
    {
        return Plugin::getInstance()->references->ensure($order);
    }

    public function account(Order $order): ?BankAccount
    {
        return Plugin::getInstance()->accounts->forOrder($order);
    }

    public function hostedUrl(Order $order, string $handle): string
    {
        return CodeController::url($order, $handle);
    }

    public function getLogo(): ?Asset
    {
        $ids = Plugin::getInstance()->getSettings()->logoId;
        return empty($ids) ? null : Craft::$app->getAssets()->getAssetById((int)$ids[0]);
    }

    /** Inline SVG of the default logo when no asset is chosen. */
    public function getDefaultLogoSvg(): string
    {
        return file_get_contents(dirname(__DIR__) . '/assets/logo-bank-transfer.svg') ?: '';
    }
}
