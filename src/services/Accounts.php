<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\services;

use craft\commerce\elements\Order;
use wmd\banktransferpaymentcodes\models\BankAccount;
use wmd\banktransferpaymentcodes\models\Settings;
use yii\base\Component;

/**
 * Which bank account an order pays into. Precedence: explicit override,
 * currency mapping, store mapping, the default account, the first account.
 */
class Accounts extends Component
{
    public function __construct(private readonly Settings $settings, array $config = [])
    {
        parent::__construct($config);
    }

    /** @return array<string, BankAccount> */
    public function all(): array
    {
        $accounts = [];
        foreach ($this->settings->accounts as $row) {
            $account = BankAccount::fromArray($row);
            if ($account->key !== '') {
                $accounts[$account->key] = $account;
            }
        }
        return $accounts;
    }

    public function get(string $key): ?BankAccount
    {
        return $this->all()[$key] ?? null;
    }

    public function default(): ?BankAccount
    {
        $all = $this->all();
        foreach ($all as $account) {
            if ($account->isDefault) {
                return $account;
            }
        }
        return $all === [] ? null : reset($all);
    }

    public function select(?string $storeHandle, string $currency, ?string $overrideKey = null): ?BankAccount
    {
        if ($overrideKey !== null && ($account = $this->get($overrideKey)) !== null) {
            return $account;
        }
        // storeAccounts/currencyAccounts are lists of rows (editable-table output), not
        // maps, so the lookup is a linear scan rather than a direct array-key fetch.
        foreach ($this->settings->currencyAccounts as $row) {
            if (strtoupper((string)($row['currency'] ?? '')) === strtoupper($currency)) {
                $account = $this->get((string)($row['account'] ?? ''));
                if ($account !== null) {
                    return $account;
                }
            }
        }
        if ($storeHandle !== null) {
            foreach ($this->settings->storeAccounts as $row) {
                if (($row['handle'] ?? null) === $storeHandle) {
                    $account = $this->get((string)($row['account'] ?? ''));
                    if ($account !== null) {
                        return $account;
                    }
                }
            }
        }
        return $this->default();
    }

    public function forOrder(Order $order): ?BankAccount
    {
        $override = null;
        if ($order->getFieldLayout()->getFieldByHandle('btpcAccount') !== null) {
            $override = (string)$order->getFieldValue('btpcAccount') ?: null;
        }
        return $this->select($order->getStore()->handle, $order->paymentCurrency ?: $order->currency, $override);
    }
}
