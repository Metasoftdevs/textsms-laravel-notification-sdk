# Changelog

All notable changes to `metasoftdevs/textsms-laravel-notification-sdk` are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial extraction from the production-hardened SMS gateway inside the
  Quest Pinnacle Investments Management System (QPIMS) codebase.
- Provider-agnostic `SmsSender` and `SmsDeliveryReportProcessor` contracts.
- `TextSmsGateway` as the default concrete provider against
  `https://sms.textsms.co.ke`.
- Three Laravel events for delivery lifecycle reconciliation:
  `SmsDispatchAccepted`, `SmsDispatchFailed`, `SmsDeliveryReported`.
- `PhoneNumberNormalizer` contract with `KenyanE164Normalizer` default and a
  `ChainedNormalizer` for multi-region consumers.
- `SmsChannel` Laravel notification channel registered as the `'sms'` driver.
- DLR webhook controller plus `VerifyTextSmsCallback` shared-secret middleware.
- `sms_dispatches` table migration (publishable stub).
- Three artisan commands: `textsms:balance`, `textsms:test`, `textsms:dispatch:show`.
- `Testing\FakeSmsGateway` helper with `assertSentTo()`, `assertNothingSent()`,
  `assertSentCount()` assertions.
- Pest + Orchestra Testbench test suite ported verbatim from QPIMS.

[Unreleased]: https://github.com/Metasoftdevs/textsms-laravel-notification-sdk/compare/HEAD
