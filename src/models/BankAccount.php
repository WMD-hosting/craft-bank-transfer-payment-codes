<?php

declare(strict_types=1);

namespace wmd\banktransferpaymentcodes\models;

use wmd\banktransferpaymentcodes\formats\Formats;
use wmd\banktransferpaymentcodes\helpers\Iban;
use wmd\banktransferpaymentcodes\references\ReferenceSchemes;

/**
 * One merchant bank account as configured in the settings table.
 * Plain object, no Craft dependency, so formats and tests can use it directly.
 */
final class BankAccount
{
    public string $key = '';
    /** The exact legal account-holder name: Verification of Payee compares it with the bank's record. */
    public string $holder = '';
    public string $iban = '';
    public string $bic = '';
    public string $currency = 'EUR';
    /** @var string[] format handles, e.g. ['hub3', 'epc'] */
    public array $formats = [];
    /** 'auto' or a scheme handle. */
    public string $referenceScheme = 'auto';
    /** Per-account override, PHP/API only (the CP table doesn't expose this). Null falls through to the plugin's Settings::$purposeTemplate. */
    public ?string $purposeTemplate = null;
    public bool $isDefault = false;
    /** Postal address, used by later invoice/QR steps. Optional. */
    public string $street = '';
    public string $postcode = '';
    public string $city = '';

    public static function fromArray(array $row): self
    {
        // Craft's editable table has no checkbox column type, so `formats` is stored
        // as a comma/space-separated singleline string and split here.
        if (isset($row['formats']) && is_string($row['formats'])) {
            $row['formats'] = preg_split('/[\s,]+/', trim($row['formats'])) ?: [];
        }

        $a = new self();
        $a->key = trim((string)($row['key'] ?? ''));
        $a->holder = trim((string)($row['holder'] ?? ''));
        $a->iban = Iban::normalise((string)($row['iban'] ?? ''));
        $a->bic = strtoupper(trim((string)($row['bic'] ?? '')));
        $a->currency = strtoupper(trim((string)($row['currency'] ?? 'EUR'))) ?: 'EUR';
        $a->formats = array_values(array_filter(array_map('strval', (array)($row['formats'] ?? []))));
        $a->referenceScheme = (string)($row['referenceScheme'] ?? 'auto') ?: 'auto';
        $a->purposeTemplate = isset($row['purposeTemplate']) && $row['purposeTemplate'] !== '' ? (string)$row['purposeTemplate'] : null;
        $a->isDefault = (bool)($row['isDefault'] ?? false);
        $a->street = trim((string)($row['street'] ?? ''));
        $a->postcode = trim((string)($row['postcode'] ?? ''));
        $a->city = trim((string)($row['city'] ?? ''));
        return $a;
    }

    public function country(): string
    {
        return Iban::country($this->iban);
    }

    /** @return array<string, string> field => message (English; Settings translates when surfacing) */
    public function validationErrors(): array
    {
        $errors = [];
        if ($this->key === '' || !preg_match('/^[a-z0-9_-]+$/i', $this->key)) {
            $errors['key'] = 'Key is required: letters, digits, dash, underscore.';
        }
        if ($this->holder === '') {
            $errors['holder'] = 'Account holder is required. Use the exact legal name the bank has on file.';
        }
        if (!Iban::isValid($this->iban)) {
            $errors['iban'] = 'IBAN checksum is wrong.';
        }
        if ($this->bic !== '' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $this->bic)) {
            $errors['bic'] = 'BIC must be 8 or 11 characters.';
        }
        if ($this->bic === '' && Iban::isValid($this->iban) && !Iban::isEea($this->country())) {
            $errors['bic'] = 'BIC is required for an account outside the EEA.';
        }
        if ($this->formats === []) {
            $errors['formats'] = 'Enable at least one code format.';
        } else {
            $unknown = array_diff($this->formats, array_keys((new Formats())->all()));
            if ($unknown !== []) {
                $errors['formats'] = sprintf('Unknown format: %s.', implode(', ', $unknown));
            }
        }
        if ($this->referenceScheme !== 'auto') {
            $schemes = (new ReferenceSchemes())->all();
            if (!isset($schemes[$this->referenceScheme])) {
                $errors['referenceScheme'] = 'Unknown reference scheme.';
            } else {
                $native = $schemes[$this->referenceScheme]->countries();
                if ($native !== [] && !in_array($this->country(), $native, true)) {
                    $errors['referenceScheme'] = sprintf('Scheme %s is only valid with an IBAN from %s.', $this->referenceScheme, implode(', ', $native));
                }
            }
        }
        return $errors;
    }
}
