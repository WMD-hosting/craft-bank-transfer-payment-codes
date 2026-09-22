<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\services;

use craft\commerce\elements\Order;
use wmd\banktransferpaymentcodes\controllers\CodeController;
use wmd\banktransferpaymentcodes\formats\FormatException;
use wmd\banktransferpaymentcodes\formats\Formats;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\models\PaymentCode;
use wmd\banktransferpaymentcodes\models\PaymentDetails;
use wmd\banktransferpaymentcodes\Plugin;
use yii\base\Component;

/**
 * Builds the list of codes for a payment, best-for-the-country first.
 */
class Codes extends Component
{
    /** Country → preferred order; anything not listed keeps the account's order. */
    private const PREFERENCE = [
        'HR' => ['hub3', 'epc'],
        'SI' => ['upn', 'epc'],
        'SK' => ['paybysquare', 'epc'],
    ];

    /**
     * @param ?\Closure(string): void $log where lenient skips are reported; the
     *     plugin passes Craft::warning(), the unit tests pass nothing, which
     *     keeps this service constructible without Craft.
     */
    public function __construct(
        private readonly Formats $formats,
        private readonly int $size,
        private readonly ?Accounts $accounts = null,
        private readonly ?References $references = null,
        private readonly ?\Closure $log = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @return PaymentCode[]
     * @throws FormatException in strict mode when a configured format cannot encode the details
     */
    public function forDetails(PaymentDetails $details, bool $strict = false): array
    {
        $available = $this->formats->all();
        $codes = [];
        foreach ($this->order($details->account->formats, $details->account->country()) as $handle) {
            if (!isset($available[$handle])) {
                if ($strict) {
                    throw new FormatException("unknown format: $handle");
                }
                $this->warn($handle, 'not a registered format');
                continue;
            }
            $format = $available[$handle];
            $reason = $format->supports($details);
            if ($reason !== null) {
                if ($strict) {
                    throw new FormatException("$handle: $reason");
                }
                $this->warn($handle, $reason);
                continue;
            }
            $codes[] = new PaymentCode($format, $format->payload($details), $this->size);
        }
        return $codes;
    }

    /**
     * A format silently dropping out of the list is the single hardest thing to
     * diagnose from a live site, so lenient mode says why in the log.
     */
    private function warn(string $handle, string $reason): void
    {
        if ($this->log !== null) {
            ($this->log)("Format $handle skipped: $reason");
        }
    }

    /** @param string[] $handles @return string[] */
    public function order(array $handles, string $country): array
    {
        $preferred = self::PREFERENCE[$country] ?? [];
        usort($handles, static function(string $a, string $b) use ($preferred): int {
            $ia = array_search($a, $preferred, true);
            $ib = array_search($b, $preferred, true);
            return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
        });
        return array_values(array_unique($handles));
    }

    /**
     * @return PaymentCode[]
     * @throws FormatException in strict mode when a configured format cannot encode the details
     */
    public function forOrder(Order $order, ?BankAccount $account = null, ?bool $strict = null): array
    {
        $details = $this->detailsFor($order, $account);
        if ($details === null) {
            return [];
        }
        $codes = $this->forDetails($details, $strict ?? \Craft::$app->getConfig()->getGeneral()->devMode);

        // Settings are read lazily here (not injected via the constructor) so the
        // Craft-free `new Codes(new Formats(), $size)` construction used by the
        // unit tests keeps working; this branch is only reached from forOrder().
        $emailMode = Plugin::getInstance()->getSettings()->emailMode;
        foreach ($codes as $code) {
            if ($emailMode === 'hosted') {
                $code->setEmailSrc(CodeController::url($order, $code->handle()));
            } else {
                $code->setEmailSrc('cid:' . EmailEmbedder::cidName($order->number, $code->handle()));
            }
        }
        return $codes;
    }

    public function detailsFor(Order $order, ?BankAccount $account = null): ?PaymentDetails
    {
        if ($this->accounts === null || $this->references === null) {
            return null;
        }
        $account ??= $this->accounts->forOrder($order);
        $ref = $this->references->ensure($order);
        if ($account === null || $ref === null) {
            return null;
        }
        $billing = $order->getBillingAddress();
        // The accounts table no longer exposes a per-account purpose template; the plugin
        // setting is the normal source. A per-account override set in PHP (BankAccount is
        // still a plain object with the property) still wins over the plugin setting.
        $template = $account->purposeTemplate ?? Plugin::getInstance()->getSettings()->purposeTemplate;
        $purpose = strtr($template, ['{number}' => (string)($order->reference ?: $order->number), '{shortNumber}' => substr($order->number, 0, 7)]);
        return new PaymentDetails(
            $account,
            // The order currency, not paymentCurrency: getOutstandingBalance() is
            // denominated in the order's own currency, and the bank account
            // settles in the store currency. Labelling that amount with the
            // payment currency would print a converted-looking figure that no
            // conversion was ever applied to.
            $order->getOutstandingBalance(),
            $order->currency,
            $ref->reference,
            $ref->model,
            $ref->isStructured,
            $purpose,
            (string)($order->reference ?: $order->number),
            payerName: $billing ? trim($billing->fullName ?: ($billing->firstName . ' ' . $billing->lastName)) ?: null : null,
            payerStreet: $billing?->addressLine1,
            payerCity: $billing?->locality,
            payerPostcode: $billing?->postalCode,
        );
    }
}
