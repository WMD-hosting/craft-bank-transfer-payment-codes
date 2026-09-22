<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\models;

use Craft;
use craft\base\Model;

/**
 * Plugin settings. Strings accept environment variables (`$BANK_IBAN`).
 */
class Settings extends Model
{
    /**
     * Bank accounts, one row each:
     * ['key' => 'main', 'holder' => 'HENA COM d.o.o.', 'iban' => 'HR12…', 'bic' => '', 'currency' => 'EUR',
     *  'formats' => ['hub3', 'epc'], 'referenceScheme' => 'auto', 'isDefault' => true]
     * A row built through PHP/the API may also set 'purposeTemplate' to override
     * {@see $purposeTemplate} for that account; the CP table doesn't expose it.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $accounts = [];

    /**
     * Store → account routing, one row each: ['handle' => 'main-store', 'account' => 'main'].
     * A list of rows, not a map: it is edited through an editable-table field, which
     * always posts back rowId => {columns}, never a flat handle => value map.
     *
     * @var array<int, array{handle?: string, account?: string}>
     */
    public array $storeAccounts = [];

    /**
     * Currency → account routing, one row each: ['currency' => 'EUR', 'account' => 'main'].
     * Same list-of-rows shape as {@see $storeAccounts} and for the same reason.
     *
     * @var array<int, array{currency?: string, account?: string}>
     */
    public array $currencyAccounts = [];

    /** @var string 'embedded' (CID attachment) or 'hosted' (signed URL). */
    public string $emailMode = 'embedded';

    /** @var int Rendered code size in pixels for PNG output. */
    public int $codeSize = 300;

    /** @var bool The shipped partial prints IBAN, holder, amount and reference as text. */
    public bool $showDetails = true;

    /** @var float Accepted difference between the paid amount and the outstanding balance. */
    public float $amountTolerance = 0.0;

    /** @var int|null Order status to set after mark-paid; null keeps Commerce's default behaviour. */
    public ?int $paidOrderStatusId = null;

    /** @var array Asset ID of the logo shown next to the payment method. */
    public array $logoId = [];

    /** @var bool Force EPC version 001 with BIC for scanners that require it. */
    public bool $epcForceBic = false;

    /**
     * Printed as the payment purpose; {number} is the order reference, {shortNumber}
     * the first 7 characters of the order number. A per-account `purposeTemplate` set
     * through PHP (not exposed in the CP table) still overrides this.
     */
    public string $purposeTemplate = 'Order {number}';

    public function rules(): array
    {
        return [
            [['emailMode'], 'in', 'range' => ['embedded', 'hosted']],
            [['codeSize'], 'integer', 'min' => 120, 'max' => 1200],
            [['amountTolerance'], 'number', 'min' => 0],
            [['purposeTemplate'], 'required'],
            [['accounts'], function(string $attribute) {
                // Per-row keys (accounts[0][key], ...) let a field-aware CP surface the
                // error next to the cell; but the settings template only ever calls
                // getErrors('accounts') (Yii's getErrors($attr) is an exact key match,
                // it does not glob "accounts[...]"), so a human-readable summary is also
                // added directly on the `accounts` attribute for every row problem, or
                // the CP shows nothing and the admin gets a silent save failure.
                $keys = [];
                foreach ($this->accounts as $i => $row) {
                    $account = BankAccount::fromArray($row);
                    foreach ($account->validationErrors() as $field => $message) {
                        $this->addError("accounts[$i][$field]", $message);
                        $this->addError($attribute, Craft::t('bank-transfer-payment-codes', 'Row {row}, {field}: {message}', [
                            'row' => $i + 1,
                            'field' => $field,
                            'message' => Craft::t('bank-transfer-payment-codes', $message),
                        ]));
                    }
                    if (in_array($account->key, $keys, true)) {
                        $duplicateMessage = 'Duplicate account key.';
                        $this->addError("accounts[$i][key]", $duplicateMessage);
                        $this->addError($attribute, Craft::t('bank-transfer-payment-codes', 'Row {row}, {field}: {message}', [
                            'row' => $i + 1,
                            'field' => 'key',
                            'message' => Craft::t('bank-transfer-payment-codes', $duplicateMessage),
                        ]));
                    }
                    $keys[] = $account->key;
                }
            }],
        ];
    }
}
