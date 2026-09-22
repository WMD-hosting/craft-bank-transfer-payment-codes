# Release Notes for Bank Transfer Payment Codes

## 1.0.0 - 2026-09-22

First release.

- "Bank transfer with payment codes" Commerce gateway, behaving like Manual: order completes with a pending authorisation, capture marks it paid.
- Four code formats: EPC QR (SEPA, GiroCode, Zahlen mit Code), HUB-3 PDF417 (Croatia), UPN QR (Slovenia), PAY by square (Slovakia), each registered through `EVENT_REGISTER_FORMATS` so third parties can add their own.
- Seven payment reference schemes: HR00, HR01, SI12, Belgian structured communication, Finnish/Estonian reference number, Slovak variable symbol, and the ISO 11649 RF fallback for everywhere else, registered through `EVENT_REGISTER_REFERENCE_SCHEMES`.
- `templates/_code.twig` partial: one visible code, a "try another code" switcher when more than one format applies, plain-text payment details with copy buttons.
- Order-email PNGs embedded as inline CID parts by default; a hosted mode serves them from a signed, anonymous URL instead for mailers that strip attachments.
- Single `markPaid()` API for the CP Capture button, ERPs and future bank-statement imports, with a `Payments::EVENT_AFTER_MARK_PAID` event.
- Croatian, Slovenian, German and Slovak translations.
