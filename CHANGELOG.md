# Release Notes for Bank Transfer Payment Codes

## 1.0.1 - 2026-09-22

> {warning} This release adds a unique index on the stored payment references. If two orders already share a reference, the migration stops and names them so you can fix the rows before running it again.

- Fixed: two orders could be given the same payment reference. The reference now comes from the order number only when that number is entirely digits, and from the order id otherwise, references are unique in the database, and a reference that is already taken is retried as the bare order id and then with a 1 to 9 suffix. If even those are taken the order still completes, without a payment code, and the reason is logged.
- Fixed: capturing a bank-transfer authorisation from the control panel did not apply the configured paid order status or fire `EVENT_AFTER_MARK_PAID`. It now does, with source `cp`.
- Fixed: the amount printed next to a code was the order's outstanding balance but was labelled with the payment currency. Codes, account routing and mark-paid all use the order's own currency now.
- Fixed: two simultaneous mark-paid calls for one order could both capture. Calls are serialised per order, and a call that cannot get the lock returns the new `locked` status. A control-panel capture takes the same lock and only applies the paid status when the capture actually settled the order.
- Fixed: a Finnish or Estonian reference generated from a one or two digit order number was shorter than the four character minimum. It is padded now.
- Fixed: an EPC QR code with a structured reference longer than 35 characters silently truncated it, which breaks the check digits. It is refused instead.
- Fixed: an EPC QR code that had to be shortened to fit its byte budget could cut into the payment reference. The payment purpose and then the beneficiary name are shortened first, and the reference is kept whole.
- Changed: the unstructured EPC remittance line puts the payment purpose before the reference, so the payer sees "Order 1234" rather than bare digits.
- Changed: a format that cannot render for an order is written to the Craft log with the reason instead of disappearing silently.
- Changed: `$VARIABLE` and alias syntax is now resolved on a bank account's holder, IBAN and BIC, both when the account is used and when the settings are validated, so an account whose IBAN is an environment variable can be saved.
- Changed: `craft.btpc.codesFor()` returns no codes when the amount is missing or not above zero, instead of rendering a zero-amount code.
- Changed: the bundled demo IBAN is a synthetic one; the previous value was a real Croatian state account.

## 1.0.0 - 2026-09-22

First release.

- "Bank transfer with payment codes" Commerce gateway, behaving like Manual: order completes with a pending authorisation, capture marks it paid.
- Four code formats: EPC QR (SEPA, GiroCode, Zahlen mit Code), HUB-3 PDF417 (Croatia), UPN QR (Slovenia), PAY by square (Slovakia), each registered through `EVENT_REGISTER_FORMATS` so third parties can add their own.
- Seven payment reference schemes: HR00, HR01, SI12, Belgian structured communication, Finnish/Estonian reference number, Slovak variable symbol, and the ISO 11649 RF fallback for everywhere else, registered through `EVENT_REGISTER_REFERENCE_SCHEMES`.
- `templates/_code.twig` partial: one visible code, a "try another code" switcher when more than one format applies, plain-text payment details with copy buttons.
- Order-email PNGs embedded as inline CID parts by default; a hosted mode serves them from a signed, anonymous URL instead for mailers that strip attachments.
- Single `markPaid()` API for the CP Capture button, ERPs and future bank-statement imports, with a `Payments::EVENT_AFTER_MARK_PAID` event.
- Croatian, Slovenian, German and Slovak translations.
