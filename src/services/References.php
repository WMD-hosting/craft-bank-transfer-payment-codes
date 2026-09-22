<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\services;

use Craft;
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

    /**
     * Hits the database every call: a concurrent writer can turn a null into a
     * row between two calls, which is exactly what ensure()'s retry loop relies on.
     *
     * @phpstan-impure
     */
    public function forOrder(Order $order): ?PaymentReference
    {
        $record = PaymentReferenceRecord::findOne(['orderId' => $order->id]);
        return $record ? PaymentReference::fromRecord($record) : null;
    }

    /**
     * Generates and stores the reference once; later calls return the stored
     * row. Returns null when the order has no bank account, or when every
     * candidate reference is already taken: order completion must not fail
     * because of this, so the order degrades to "no payment code" and the
     * reason goes to the log.
     */
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
        $input = new ReferenceInput((string)($order->reference ?: $order->number), (int)$order->id);

        foreach (self::attempts($input) as $attempt) {
            $reference = $scheme->generate($attempt);
            // Cheap pre-check so a known collision costs a SELECT rather than a
            // failed INSERT; the catch below still covers the race that opens
            // between this check and the insert.
            if (PaymentReferenceRecord::find()->where(['normalised' => $scheme->normalise($reference)])->exists()) {
                continue;
            }
            try {
                return $this->store($order, $account->key, $scheme, $reference);
            } catch (IntegrityException) {
                // Either a concurrent caller stored this order's reference first
                // (thank-you page render vs. the order-complete listener), in
                // which case its row is the answer, or another order took this
                // reference in the meantime and the next candidate is tried.
                if (($winner = $this->forOrder($order)) !== null) {
                    return $winner;
                }
            }
        }

        Craft::warning(
            "No free payment reference for order {$order->id} on account {$account->key}: every candidate derived from the order id is already stored. The order completes without a payment code.",
            'bank-transfer-payment-codes',
        );
        return null;
    }

    /**
     * The references to try, in order: the one the order number asks for, then
     * the bare order id, then the order id with a 1 to 9 suffix. Commerce does
     * the same thing for order references. Separated from the database work and
     * free of Craft so the sequence itself is unit-testable.
     *
     * @return ReferenceInput[]
     */
    public static function attempts(ReferenceInput $input): array
    {
        $id = (string)$input->orderId;
        $attempts = [$input];
        if ($input->digits() !== $id) {
            $attempts[] = new ReferenceInput($id, $input->orderId);
        }
        foreach (range(1, 9) as $suffix) {
            $attempts[] = new ReferenceInput($id . $suffix, $input->orderId);
        }
        return $attempts;
    }

    private function store(Order $order, string $accountKey, ReferenceSchemeInterface $scheme, string $reference): PaymentReference
    {
        $record = new PaymentReferenceRecord();
        $record->orderId = (int)$order->id;
        $record->accountKey = $accountKey;
        $record->scheme = $scheme->handle();
        $record->model = $scheme->model() ?? '';
        $record->reference = $reference;
        $record->normalised = $scheme->normalise($reference);
        $record->isStructured = $scheme->handle() !== 'sk';
        $record->save(false);
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
