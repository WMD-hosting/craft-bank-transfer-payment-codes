# Bank Transfer Payment Codes for Craft Commerce

A Craft Commerce gateway for plain bank transfer that prints scannable
payment codes on the order confirmation, in order emails and in PDFs: EPC QR
(SEPA, GiroCode, Zahlen mit Code), HUB-3 PDF417 (Croatia), UPN QR (Slovenia)
and PAY by square (Slovakia), alongside a national payment reference and a
single mark-paid API for staff, ERPs and later bank-statement imports.

Code scanning is strong in Germany, Austria, Belgium, Slovenia, Slovakia and
Croatia, partial in the Netherlands (ING and bunq scan an EPC QR code,
Rabobank and ABN AMRO do not), and weak or unverified in the rest of the
eurozone. That means the plain-text payment details this plugin prints next
to every code, IBAN, holder, amount, reference, are the primary way to pay
for roughly half the eurozone, not a fallback for people who cannot scan.
Since 9 October 2025, Verification of Payee is mandatory across the EU: the
receiving bank checks the payee name carried in the code against the actual
account holder and warns the payer on a mismatch. The "Account holder"
setting on every bank account must be the exact legal name the bank has on
file, not a trading name, or customers will see a "no match" or "close
match" warning in their own banking app before they confirm the transfer.

## Requirements

- Craft CMS 5.0 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- `ext-gd` (required by `tecnickcom/tc-lib-barcode` for HUB-3 PDF417 and by
  `bacon/bacon-qr-code` for PNG output), `ext-iconv`, `ext-mbstring`

## Installation

```sh
composer require wmd/craft-bank-transfer-payment-codes
php craft plugin/install bank-transfer-payment-codes
```

## Setup

### Gateway

**Commerce → System Settings → Gateways → New gateway**, type **Bank
transfer with payment codes**. The gateway behaves like Commerce's own
Manual gateway: the order completes with a pending authorisation, and
capturing that authorisation (from the CP, or through `markPaid()`) marks it
paid. The moment an order completes on this gateway, the plugin generates
and stores its payment reference.

### Bank accounts

**Settings → Plugins → Bank Transfer Payment Codes**, one row per merchant
account the store can pay into:

| Column | Notes |
|---|---|
| Key | short id used for `codes(order, accountKey)` and for the `storeAccounts` / `currencyAccounts` routing rows |
| Account holder (legal name) | the exact name your bank has on file; Verification of Payee compares it |
| IBAN | validated by its mod-97 checksum |
| BIC | required outside the EEA, optional inside it (unless "Force BIC" is on) |
| Currency | account settlement currency, default `EUR` |
| Formats | space or comma separated format handles: `epc`, `hub3`, `upn`, `paybysquare` |
| Reference scheme | `auto` (by IBAN country) or a specific scheme handle |
| Street / Postcode and city | merchant address, used by HUB-3, UPN and PAY by square |
| Default | the fallback account when nothing else matches |

The payment purpose (e.g. `Order {number}`, `{shortNumber}` also available)
is a single plugin-level setting, not a per-account column, see below.
`BankAccount::$purposeTemplate` is `null` by default; a per-account
override set in PHP wins over the plugin setting for that account.

Account routing, most specific first: an explicit override (a `btpcAccount`
field on the order, if you add one), then a currency mapping, then a store
mapping, then the default account, then the first configured account.

### Settings

| Setting | Type | Default | Notes |
|---|---|---|---|
| `accounts` | array of rows | `[]` | bank accounts, see above |
| `storeAccounts` | array of rows | `[]` | store handle → account key |
| `currencyAccounts` | array of rows | `[]` | currency → account key |
| `purposeTemplate` | string | `Order {number}` | printed as the payment purpose; `{number}` is the order reference, `{shortNumber}` the first 7 characters of the order number |
| `emailMode` | string, `embedded` or `hosted` | `embedded` | `embedded` attaches each PNG as an inline (CID) part of the Commerce email; `hosted` links to a signed, anonymous URL instead |
| `codeSize` | int (pixels) | `300` | 120 to 1200 |
| `showDetails` | bool | `true` | the shipped partial also prints IBAN, holder, amount and reference as copyable text |
| `amountTolerance` | float | `0.0` | accepted difference between a reported payment and the outstanding balance before `markPaid()` refuses it |
| `paidOrderStatusId` | int or null | `null` | order status to set after a successful mark-paid; `null` keeps Commerce's own behaviour |
| `logoId` | asset id array | `[]` | shown next to the gateway at checkout; falls back to the bundled neutral SVG |
| `epcForceBic` | bool | `false` | always emit EPC version 001 with a BIC, for scanners (some strict German apps) that require one even inside the EEA |

## Formats

| Handle | Countries | What it needs |
|---|---|---|
| `epc` | eurozone default; skipped for non-EUR orders | EUR currency, a valid IBAN, a BIC for non-EEA IBANs (or when Force BIC is on); emitted without an ECI segment, so it also passes strict scanners such as Erste George |
| `hub3` | HR | a Croatian IBAN, EUR, an HR reference model (HR00 or HR01); truncates the payee name to 25 characters (the HUB-3 field limit); needs `ext-gd` |
| `upn` | SI | a Slovenian IBAN, EUR, an SI12 model or an RF reference |
| `paybysquare` | SK | a beneficiary name (mandatory since 2025-04-01), a positive amount, an ISO 4217 currency code; the web SVG always renders the mandatory "PAY by square" logo frame |

## Reference schemes

| Handle | Countries | Reference | Check |
|---|---|---|---|
| `hr00` | HR (default) | order number digits | none |
| `hr01` | HR (opt-in) | order number digits | ISO 7064 MOD 11,10 |
| `si12` | SI | 12-digit number | ISO 7064 MOD 11,10 |
| `be` | BE | `+++XXX/XXXX/XXXCC+++` | mod 97 on the first 10 digits, 0 becomes 97 |
| `fi` | FI, EE | up to 19 or 20 digits | weights 7, 3, 1 from the right |
| `sk` | SK | variable symbol, up to 10 digits | none |
| `rf` | any (the fallback for everything else) | `RF` + 2 check digits + up to 21 characters | ISO 11649 mod 97 |

Digits come from the order number, or the order id when the number carries
none. `auto` on a bank account picks the scheme by the account's IBAN
country; anything not listed above (FR, ES, PT, IT, IE, GR, the Baltics,
...) falls back to `rf`.

## Twig API

`craft.bankTransferPaymentCodes`, or the shorter `craft.btpc`.

### Confirmation page, order email, order PDF

The shipped partial handles all three placements; `context` only changes how
the code is rendered (inline SVG with a "try another code" switcher on the
web, a PNG through `emailSrc()` in email, a PNG data URI in PDF, since
Dompdf cannot load external images).

```twig
{# order confirmation page #}
{% if order.gateway and order.gateway.handle == 'bankTransfer' and order.hasOutstandingBalance() %}
  {% include 'bank-transfer-payment-codes/_code' with { order: order, context: 'web' } only %}
{% endif %}
```

```twig
{# Commerce order email #}
{% if order.gateway and order.gateway.handle == 'bankTransfer' and order.hasOutstandingBalance() %}
  {% include 'bank-transfer-payment-codes/_code' with { order: order, context: 'email' } only %}
{% endif %}
```

```twig
{# Commerce order PDF #}
{% if order.gateway and order.gateway.handle == 'bankTransfer' and order.hasOutstandingBalance() %}
  {% include 'bank-transfer-payment-codes/_code' with { order: order, context: 'pdf' } only %}
{% endif %}
```

`bankTransfer` above is only the handle the demo store happened to give the
gateway when creating it in the CP; use whatever handle yours has. The
guard matters: nothing in `craft.btpc.codes()` itself checks that the order
is actually on this gateway, an account is selected by store and currency
routing regardless, so without this check the partial would print bank
codes on an order paid by card.

Building your own markup instead of the partial:

```twig
{% set codes = craft.btpc.codes(order) %}
{% set details = craft.btpc.details(order) %}
{% for code in codes %}
  <figure>
    {{ code.svg()|raw }}
    <figcaption>{{ code.label() }}</figcaption>
  </figure>
{% endfor %}
{% if details %}
  <p>{{ details.account.holder }} · {{ details.account.iban }} · {{ details.amount }} {{ details.currency }} · {{ details.reference }}</p>
{% endif %}
```

### Codes for a hand-built payment (`codesFor()`)

For an invoice, a deposit, or anything that is not a Commerce order:

```twig
{% set codes = craft.btpc.codesFor({
  account: 'main',
  amount: 149.00,
  currency: 'EUR',
  reference: 'RF18539007547034',
  structured: true,
  purpose: 'Invoice 2026-0042',
  number: '2026-0042',
}) %}
{% for code in codes %}
  <img src="{{ code.emailSrc() }}" alt="{{ code.label() }}">
{% endfor %}
```

`account` falls back to the default bank account when left out; `currency`
defaults to `EUR`; `structured` defaults to `true`. When `number` is given,
it is treated as an order-number-like key and `emailSrc()` is pre-filled
with the same `cid:btpc-{number}-{format}.png` convention
`craft.btpc.codes(order)` uses for a real order, so embedding the PNG under
that filename in your own mailer call (`embedContent()` on the Symfony
email, `fileName` set to that same string) is enough for it to show up.
Without `number`, `emailSrc()` falls back to a `data:` URI, which is fine on
the web and in a PDF but blocked by most mail clients, so call
`code.setEmailSrc()` yourself first if you are emailing a hand-built code
without a `number` (or want a hosted URL instead of a CID). The plugin's own
`EmailEmbedder` only attaches that CID part automatically to a Commerce
order email when `number` equals that email's own order number, so for an
invoice, a deposit, or any document sent outside a Commerce order email,
either use a hosted URL (`craft.btpc.hostedUrl()`, needs a real order) or
embed the PNG under that filename yourself.

## PHP API

```php
use wmd\banktransferpaymentcodes\Plugin as Btpc;

$result = Btpc::getInstance()->payments->markPaid('HR00 2026001', 24.60, 'erp');
if ($result->ok) {
    // $result->status === 'paid'
} else {
    // 'underpaid', 'overpaid', 'already_paid', 'not_found', 'wrong_gateway',
    // 'no_transaction' or 'capture_failed'; $result->message explains it
}
```

`markPaid(Order|string $orderOrReference, ?float $amount = null, string
$source = 'api', string $note = ''): MarkPaidResult`. The first argument
takes either a Commerce `Order` or a raw reference string, matched against
every registered scheme's normalised form. Pass `null` for `$amount` to
trust the caller outright, or a value compared against the outstanding
balance within the configured tolerance. `$source` is recorded on the
capture transaction's note (`cp`, `api`, `camt`, `erp`, or your own label)
and passed through to `Payments::EVENT_AFTER_MARK_PAID`. Commerce's own CP
Capture button on a bank-transfer order routes through this same method.

The other services, `accounts`, `references`, `codes`, take and return
typed values only, no request objects, so they are straightforward to call
from a console command, a queue job, or a future MCP tool. Full signatures
are in `llms.txt`.

## Events

Every registration event shares one payload class,
`wmd\banktransferpaymentcodes\events\RegisterComponentsEvent` (a
`components` array, handle to instance).

```php
use wmd\banktransferpaymentcodes\events\RegisterComponentsEvent;
use wmd\banktransferpaymentcodes\formats\Formats;
use yii\base\Event;

Event::on(Formats::class, Formats::EVENT_REGISTER_FORMATS, function(RegisterComponentsEvent $e) {
    $e->components['spayd'] = new MySpaydFormat(); // Czech SPAYD, for example
});
```

```php
use wmd\banktransferpaymentcodes\events\RegisterComponentsEvent;
use wmd\banktransferpaymentcodes\references\ReferenceSchemes;
use yii\base\Event;

Event::on(ReferenceSchemes::class, ReferenceSchemes::EVENT_REGISTER_REFERENCE_SCHEMES, function(RegisterComponentsEvent $e) {
    $e->components['pl'] = new MyPolishReferenceScheme();
});
```

```php
use wmd\banktransferpaymentcodes\events\MarkPaidEvent;
use wmd\banktransferpaymentcodes\services\Payments;
use yii\base\Event;

Event::on(Payments::class, Payments::EVENT_AFTER_MARK_PAID, function(MarkPaidEvent $e) {
    if ($e->result->ok && $e->source === 'camt') {
        // tell the ERP the invoice is settled
    }
});
```

## A note on Blitz

The order confirmation page (`/checkout/complete?number=...`) is safe under
Blitz's default settings: a query string on the URL means Blitz does not
cache the request at all. If you turn on `queryStringCaching`, exclude
`checkout/*` explicitly, or every visitor will be served the first paid
customer's confirmation page and payment codes.

## Compatibility

Every combination below is currently **untested**. This table is filled in
from real scans as they are reported (tracked separately, see the roadmap);
until then, treat every code format as "should work per spec, not yet
verified against this particular app".

| Banking app | EPC QR | HUB-3 | UPN QR | PAY by square |
|---|---|---|---|---|
| George / Erste (HR) | untested | untested | untested | untested |
| PBZ | untested | untested | untested | untested |
| Zaba | untested | untested | untested | untested |
| OTP (HR) | untested | untested | untested | untested |
| RBA | untested | untested | untested | untested |
| Revolut | untested | untested | untested | untested |
| NLB | untested | untested | untested | untested |
| SKB | untested | untested | untested | untested |
| Tatra banka | untested | untested | untested | untested |
| Slovenská sporiteľňa | untested | untested | untested | untested |
| Sparkasse (DE) | untested | untested | untested | untested |
| ING (NL) | untested | untested | untested | untested |
| bunq | untested | untested | untested | untested |
| KBC | untested | untested | untested | untested |
| Belfius | untested | untested | untested | untested |

## Roadmap

- **1.1, reconciliation**: CAMT.053 (001.08, the HR national guide) upload
  in the CP and an IMAP mailbox watcher; an exact reference and amount match
  auto-marks the order paid, anything else lands in a review list.
- **2.0, bank feed**: a provider interface plus a WMD relay holding one
  Enable Banking contract (best HR/SI coverage today), Ponto as a second
  adapter, offered as a subscription add-on with a data processing
  agreement.
- Further formats through `EVENT_REGISTER_FORMATS`: Finnish bank barcode,
  Swiss QR-bill, Czech SPAYD, Polish 2D, Hungarian qvik.

## Licence

MIT.
