<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\services;

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use wmd\banktransferpaymentcodes\models\PaymentReference;
use wmd\banktransferpaymentcodes\records\PaymentReferenceRecord;
use wmd\banktransferpaymentcodes\references\ReferenceInput;
use wmd\banktransferpaymentcodes\references\ReferenceSchemeInterface;
use wmd\banktransferpaymentcodes\references\ReferenceSchemes;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Generates and persists the one payment reference an order gets: generated
 * once at order completion, then reused for every code render and for
 * matching incoming bank statement lines back to the order.
 */
class References extends Component
{
    public function __construct(private readonly Accounts $accounts, private readonly ReferenceSchemes $schemes, array $config = [])
    {
        parent::__construct($config);
    }

    public function forOrder(Order $order): ?PaymentReference
    {
        $record = PaymentReferenceRecord::findOne(['orderId' => $order->id]);
        return $record ? PaymentReference::fromRecord($record) : null;
    }

    /** Generates and stores the reference once; later calls return the stored row. */
    public function ensure(Order $order): ?PaymentReference
    {
        if (($existing = $this->forOrder($order)) !== null) {
            return $existing;
        }
        $account = $this->accounts->forOrder($order);
        if ($account === null || !$order->id) {
            return null;
        }
        $scheme = $this->schemes->forAccount($account);
        $reference = $scheme->generate(new ReferenceInput((string)($order->reference ?: $order->number), (int)$order->id));

        $record = new PaymentReferenceRecord();
        $record->orderId = $order->id;
        $record->accountKey = $account->key;
        $record->scheme = $scheme->handle();
        $record->model = $scheme->model() ?? '';
        $record->reference = $reference;
        $record->normalised = $scheme->normalise($reference);
        $record->isStructured = $scheme->handle() !== 'sk';
        try {
            $record->save(false);
        } catch (IntegrityException $e) {
            // Lost a race against a concurrent caller (thank-you page render vs.
            // the order-complete listener both calling ensure() for the same
            // order): the other caller's insert won the unique orderId index,
            // so fetch and return what it stored instead of failing.
            $winner = PaymentReferenceRecord::findOne(['orderId' => $order->id]);
            if ($winner !== null) {
                return PaymentReference::fromRecord($winner);
            }
            throw $e;
        }
        return PaymentReference::fromRecord($record);
    }

    /**
     * Every normalised form a raw reference could plausibly be, across every
     * registered scheme, plus a scheme-agnostic "strip everything but letters
     * and digits" fallback. A pure function so it stays testable without
     * Craft, and so lookups don't hardcode per-scheme prefix regexes here
     * that would drift out of sync with the schemes (or miss third-party
     * schemes registered via EVENT_REGISTER_REFERENCE_SCHEMES).
     *
     * @param ReferenceSchemeInterface[] $schemes
     * @return string[] deduplicated, non-empty
     */
    public static function candidates(string $reference, array $schemes): array
    {
        $candidates = array_map(static fn(ReferenceSchemeInterface $scheme): string => $scheme->normalise($reference), $schemes);
        $candidates[] = preg_replace('/[^A-Za-z0-9]+/', '', $reference) ?? '';
        return array_values(array_unique(array_filter($candidates, static fn(string $c): bool => $c !== '')));
    }

    public function findOrder(string $reference): ?Order
    {
        $candidates = self::candidates($reference, $this->schemes->all());
        if ($candidates === []) {
            return null;
        }
        $record = PaymentReferenceRecord::findOne(['normalised' => $candidates]);
        return $record ? Commerce::getInstance()->getOrders()->getOrderById($record->orderId) : null;
    }
}
