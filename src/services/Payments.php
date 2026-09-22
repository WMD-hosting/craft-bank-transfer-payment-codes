<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use wmd\banktransferpaymentcodes\events\MarkPaidEvent;
use wmd\banktransferpaymentcodes\gateways\BankTransferGateway;
use wmd\banktransferpaymentcodes\models\MarkPaidResult;
use wmd\banktransferpaymentcodes\models\Settings;
use yii\base\Component;

class Payments extends Component
{
    public const EVENT_AFTER_MARK_PAID = 'afterMarkPaid';

    /**
     * True while this service is inside its own captureTransaction() call, so
     * the EVENT_AFTER_CAPTURE_TRANSACTION listener in Plugin::init() can tell a
     * capture it started from one the CP's Capture button started, and not
     * apply the paid status and fire EVENT_AFTER_MARK_PAID twice.
     */
    private static bool $capturing = false;

    public function __construct(private readonly References $references, private readonly Settings $settings, array $config = [])
    {
        parent::__construct($config);
    }

    /** Whether the capture currently running was started by {@see markPaid()}. */
    public static function isCapturing(): bool
    {
        return self::$capturing;
    }

    /**
     * Marks a bank-transfer order paid by capturing its pending authorisation.
     * $amount null means "trust the caller"; otherwise it must match the
     * order's outstanding balance, in the order's own currency, within the
     * configured tolerance.
     */
    public function markPaid(Order|string $orderOrReference, ?float $amount = null, string $source = 'api', string $note = ''): MarkPaidResult
    {
        $order = $orderOrReference instanceof Order ? $orderOrReference : $this->references->findOrder($orderOrReference);
        if ($order === null) {
            return new MarkPaidResult(false, 'not_found', message: 'No order carries that payment reference.');
        }
        if (!$order->getGateway() instanceof BankTransferGateway) {
            return new MarkPaidResult(false, 'wrong_gateway', $order, message: 'Order is not on the bank transfer gateway.');
        }

        // Two reconciliation runs (a CAMT import and an ERP webhook, say) hitting
        // the same order at once would both read isPaid = false and both capture.
        $result = $this->withLock((int)$order->id, function() use ($order, $amount, $source, $note): MarkPaidResult {
            // Re-read inside the lock: the order this caller holds may have been
            // captured by whoever held the lock a moment ago.
            $fresh = Commerce::getInstance()->getOrders()->getOrderById((int)$order->id) ?? $order;
            $decision = PaymentDecision::decide($fresh->getOutstandingBalance(), $amount, $this->settings->amountTolerance, $fresh->isPaid);
            if ($decision !== 'pay') {
                return new MarkPaidResult(false, $decision, $fresh, message: "Payment $decision.");
            }
            $pending = $this->pendingAuthorisation($fresh);
            if ($pending === null) {
                return new MarkPaidResult(false, 'no_transaction', $fresh, message: 'No pending authorisation to capture.');
            }
            $pending->note = trim("Marked paid via $source. $note");
            self::$capturing = true;
            try {
                $captured = Commerce::getInstance()->getPayments()->captureTransaction($pending);
            } finally {
                self::$capturing = false;
            }
            if ($captured->status !== TransactionRecord::STATUS_SUCCESS) {
                return new MarkPaidResult(false, 'capture_failed', $fresh, $captured, 'Capture did not succeed.');
            }
            return $this->settle($fresh, $captured, $source, $note);
        });

        return $result ?? new MarkPaidResult(false, 'locked', $order, message: 'Another mark-paid is in progress.');
    }

    /**
     * Runs $fn holding this order's mark-paid lock, or returns null when
     * another caller holds it. One lock name for both paths, so a CP capture
     * and an API mark-paid cannot settle the same order at the same time.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T|null null only when the lock could not be acquired
     */
    private function withLock(int $orderId, \Closure $fn): mixed
    {
        $mutex = Craft::$app->getMutex();
        $lock = 'btpc-mark-paid-' . $orderId;
        if (!$mutex->acquire($lock, 5)) {
            return null;
        }
        try {
            return $fn();
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Completes a capture that Commerce's own CP Capture button performed on a
     * bank-transfer order, so clicking Capture in the CP has the same effect as
     * calling {@see markPaid()}: the configured paid status is applied and
     * EVENT_AFTER_MARK_PAID fires with source `cp`. Wired to
     * `craft\commerce\services\Payments::EVENT_AFTER_CAPTURE_TRANSACTION` in
     * Plugin::init(); a no-op for captures this service started itself, for a
     * capture that did not leave the order paid, and while another mark-paid
     * holds the order's lock.
     */
    public function afterCpCapture(Transaction $captured): void
    {
        if (self::$capturing || $captured->status !== TransactionRecord::STATUS_SUCCESS || !$captured->orderId) {
            return;
        }
        $this->withLock((int)$captured->orderId, function() use ($captured): void {
            // Commerce updates the order's paid information while saving the
            // capture transaction, before this event fires, so a re-read here
            // says whether the capture actually settled the order. A partial
            // capture leaves a balance and must not flip the order to paid.
            $order = Commerce::getInstance()->getOrders()->getOrderById((int)$captured->orderId);
            if ($order === null || !$order->isPaid || !$order->getGateway() instanceof BankTransferGateway) {
                return;
            }
            $this->settle($order, $captured, 'cp');
        });
    }

    /**
     * The tail both mark-paid paths share: apply the configured paid order
     * status and announce the result.
     */
    private function settle(Order $order, Transaction $captured, string $source, string $note = ''): MarkPaidResult
    {
        if ($this->settings->paidOrderStatusId) {
            $order->orderStatusId = $this->settings->paidOrderStatusId;
            $order->message = trim("Paid by bank transfer ($source). $note");
            Craft::$app->getElements()->saveElement($order, false);
        }
        $result = new MarkPaidResult(true, 'paid', $order, $captured);
        if ($this->hasEventHandlers(self::EVENT_AFTER_MARK_PAID)) {
            $this->trigger(self::EVENT_AFTER_MARK_PAID, new MarkPaidEvent(['result' => $result, 'source' => $source]));
        }
        return $result;
    }

    private function pendingAuthorisation(Order $order): ?Transaction
    {
        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->type === TransactionRecord::TYPE_AUTHORIZE && $transaction->status === TransactionRecord::STATUS_SUCCESS && $transaction->canCapture()) {
                return $transaction;
            }
        }
        return null;
    }
}
