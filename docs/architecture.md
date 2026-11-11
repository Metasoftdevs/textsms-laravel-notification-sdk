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
   Outbound audit and alerting can live in consumer listeners.
   Delivery callbacks invoke no application actions. The gateway has no `App\Models\Receipt`
   coupling.
3. **Two exception classes split caller-fault from provider-fault.**
   `SmsDispatchException` for bad input (phone, body, bulk size limit);
   `SmsProviderException` for everything the provider does. Ops dashboards
   can filter outage signal from code-quality signal.
4. **`sms_dispatches` records submission attempts.** Callback payloads are
   informational and do not change submission state or timestamps.
5. **Tests are the contract.** Every behavioural change ships with at
   least one regression test. Local checks use Pest, PHPStan
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
│   └── SmsDeliveryReported         # legacy class, not emitted
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
   ├─ usable success → markSent → SmsDispatchAccepted
   └─ otherwise         → markFailed → SmsDispatchFailed → throw SmsProviderException
```

Acceptance requires successful HTTP status, code 200, a usable provider ID
and unambiguous request/response correlation. A code alone is insufficient.
Unknown outcomes are saved as failed while retaining uncertainty in diagnostics.

## Lifecycle: inbound delivery report

```text
POST {prefix}/textsms/dlr?token=...
  -> VerifyTextSmsCallback (constant-time secret comparison)
  -> throttle:textsms-dlr (600 authenticated callbacks/minute by default)
  -> controller strips token and validates messageid/status shapes
  -> recorder finds TextSMS dispatch by provider_message_id
  -> writes only dlr_payload, with model events and timestamps suppressed
  -> HTTP 200 (unknown IDs also acknowledged; storage failures propagate)
```

No classification, precedence engine, delivery event, observer or application
action runs. Repeated/reordered callbacks simply replace the informational
payload. Submission status and timestamps remain unchanged.
See [webhooks](webhooks.md) for configuration and live-provider limitations.

## Send retries

Single and bulk sends allow at most two Laravel-layer HTTP attempts: the
initial request and one retry after structured cURL 6/7 DNS/connect evidence.
Timeouts, empty replies, HTTP responses and context-free failures are not
retried. Send redirects are disabled to prevent hidden POST forwarding.
Balance retry behavior remains separate and unchanged. This is not an
on-wire exactly-once guarantee. See [operations](operations.md).

## Three response shapes the parser handles

The parser supports the two response shapes in the bundled documentation and
synthetic fixtures, plus an implicit third for unexpected replies. Current
candidate live-provider captures remain pending. `parseSendResponse()` and
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
