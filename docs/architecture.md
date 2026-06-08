# Architecture

This document is the deep-dive companion to the README's "Architecture at
a glance" diagram.

## Design principles inherited from the production benchmark

The package was extracted from a Laravel application that ran seven
incremental hardening rounds in production. Five principles emerged from
that history and govern every change in this package:

1. **Provider quirks are isolated to one class.** Anything provider-specific
   (TextSMS's misspelled `respose-code` key, the response-shape matrix,
   the `clientsmsid` round-trip) lives in `TextSmsGateway`. Consumers, the
   channel, and other providers never know about it.
2. **The gateway emits events, never reaches into application domain.**
   Receipt-side reconciliation, audit logging, resend logic — all live in
   consumer-side listeners. The gateway has no `App\Models\Receipt`
   coupling.
3. **Two exception classes split caller-fault from provider-fault.**
   `SmsDispatchException` for bad input (phone, body, bulk size limit);
   `SmsProviderException` for everything the provider does. Ops dashboards
   can filter outage signal from code-quality signal.
4. **`sms_dispatches` is the source of truth.** Any caller-side
   denormalisation (e.g. a receipt's `sms_status` column) is mirrored from
   this table via an event listener — never written by the gateway.
5. **Tests are the contract.** Every behavioural change ships with at
   least one regression test. CI enforces 52+ Pest assertions, PHPStan
   level 8, and Pint code-style on every PR.

## Module map

```
src/
├── Contracts/
│   ├── SmsSender                   # outbound: send, sendBulk, balance
│   ├── SmsDeliveryReportProcessor  # inbound: recordDeliveryReport
│   └── PhoneNumberNormalizer       # normalize, isValid
├── Messages/
│   └── SmsMessage                  # immutable DTO returned from Notification::toSms()
├── Notifications/Channels/
│   └── SmsChannel                  # registered as 'sms' driver via ChannelManager
├── Gateways/
│   ├── TextSmsGateway              # default concrete provider (https://sms.textsms.co.ke)
│   └── LogOnlySmsGateway           # local-dev / CI no-network alternative
├── PhoneNumbers/
│   ├── KenyanE164Normalizer        # default, Safaricom 7xx + Airtel/Telkom 1xx
│   └── ChainedNormalizer           # composes multiple normalisers
├── Models/
│   └── SmsDispatch                 # source-of-truth row, polymorphic notifiable
├── Http/
│   ├── Controllers/TextSmsCallbackController
│   └── Middleware/VerifyTextSmsCallback
├── Events/
│   ├── SmsDispatchAccepted         # status: pending → sent
│   ├── SmsDispatchFailed           # status: pending → failed
│   └── SmsDeliveryReported         # DLR ingest
├── Exceptions/
│   ├── SmsDispatchException        # caller fault
│   └── SmsProviderException        # provider fault (carries providerCode + providerErrors)
├── Console/Commands/
│   ├── BalanceCommand
│   ├── TestSendCommand
│   └── ShowDispatchCommand
├── Testing/
│   └── FakeSmsGateway              # consumer test double
└── TextSmsNotificationServiceProvider
```

## Lifecycle: outbound send

```
your code: $user->notify(new YourSmsNotification(...))
                         │
                         ▼
Laravel Notification dispatcher → ChannelManager → SmsChannel::send()
                         │
                         ▼
1. method_exists toSms?              [no → null]
2. routeNotificationForSms returns a recipient?  [no → null]
3. $sender->send(recipient, message, notifiable)
                         │
                         ▼
TextSmsGateway::send()
                         │
                         ▼
1. PhoneNumberNormalizer::normalize($recipient)  [null → SmsDispatchException]
2. Validate non-empty body                       [empty → SmsDispatchException]
3. Pre-insert sms_dispatches row (status=pending)
4. POST to /api/services/sendsms/
5. parseSendResponse() → Shape A / Shape B / Shape C
   ├─ Shape A + code=200 → markSent → SmsDispatchAccepted
   └─ otherwise         → markFailed → SmsDispatchFailed → throw SmsProviderException
```

## Lifecycle: inbound DLR

```
TextSMS portal posts to: POST {prefix}/textsms/dlr?token=...
                         │
                         ▼
VerifyTextSmsCallback (constant-time token check)
   ├─ missing/wrong token → 401
   └─ unconfigured secret  → 500
                         │
                         ▼
TextSmsCallbackController::dlr()
   ├─ missing messageid → 422
   └─ ok                → SmsDeliveryReportProcessor::recordDeliveryReport()
                                    │
                                    ▼
                          TextSmsGateway::recordDeliveryReport()
                                    │
                                    ▼
1. Lookup SmsDispatch by provider_message_id
   └─ unknown → log + return null (200 to provider so it stops retrying)
2. classifyDeliveryReportStatus($rawStatus)
   ├─ undeliver/fail/expir/reject → 'failed'  (checked FIRST so "undelivered" doesn't match "deliver")
   ├─ deliver/delivrd/deliv       → 'delivered'
   └─ else                        → 'unknown' (persist payload, leave status)
3. Update dispatch row + dlr_payload
4. SmsDeliveryReported::dispatch(...)
                                    │
                                    ▼
your application's listener → reconciles domain state
```

## Three response shapes the parser handles

TextSMS emits two distinct response shapes plus an implicit third when
something unexpected happens on the wire. `parseSendResponse()` and
`parseBulkResponse()` reduce all three into a uniform internal outcome so
`send()`/`sendBulk()` stay flat.

| `parse_source` | When | Example |
|---|---|---|
| `responses` | HTTP 200 with `responses[]` populated. Each row carries `respose-code` (sic). | `{"responses":[{"respose-code":200,"messageid":659693034,"networkid":"1"}]}` |
| `top-level` | HTTP 422 (typically) with a global error: inactive sender ID, malformed payload, missing global field. Carries `errors` map. | `{"response-code":1003,"response-description":"Validation Errors...","errors":{"shortcode":{"Shortcode":"Sender ID is inactive or unassigned"}}}` |
| `unparseable` | Anything else (5xx with no JSON, surprise HTML, content-type drift). Diagnostic stub carries `_http_status` + first 200 chars of body. | `<html>503 Bad Gateway</html>` |

Failed rows persist a structured diagnostics block under
`sms_dispatches.metadata.provider_diagnostics`:

```json
{
  "provider": "textsms",
  "parse_source": "top-level",
  "errors": {"shortcode": {"Shortcode": "Sender ID is inactive or unassigned"}},
  "http_status": null,
  "raw_body_truncated": null
}
```

The key is namespaced (`provider_diagnostics`, not the bare `provider`)
because `metadata` is forwarded from `SmsMessage::metadata` and callers may
legitimately set their own `provider` field. The block is self-describing
so it remains interpretable when inspected in isolation.
