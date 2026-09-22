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

    public function __construct(private readonly References $references, private readonly Settings $settings, array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * Marks a bank-transfer order paid by capturing its pending authorisation.
     * $amount null means "trust the caller"; otherwise it must match the
     * outstanding balance within the configured tolerance.
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
        $decision = PaymentDecision::decide($order->getOutstandingBalance(), $amount, $this->settings->amountTolerance, $order->isPaid);
        if ($decision !== 'pay') {
            return new MarkPaidResult(false, $decision, $order, message: "Payment $decision.");
        }
        $pending = $this->pendingAuthorisation($order);
        if ($pending === null) {
            return new MarkPaidResult(false, 'no_transaction', $order, message: 'No pending authorisation to capture.');
        }
        $pending->note = trim("Marked paid via $source. $note");
        $captured = Commerce::getInstance()->getPayments()->captureTransaction($pending);
        if ($captured->status !== TransactionRecord::STATUS_SUCCESS) {
            return new MarkPaidResult(false, 'capture_failed', $order, $captured, 'Capture did not succeed.');
        }
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
