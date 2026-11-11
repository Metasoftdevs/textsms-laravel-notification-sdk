# Contributing

Thanks for your interest in `metasoftdevs/textsms-laravel-notification-sdk`.

## Philosophy

This package was extracted from a single production application
(Quest Pinnacle Investments Management System) after seven rounds of in-app
hardening. The guiding principles for any change are inherited from that
hardening trail:

1. **Provider quirks are isolated to one class.** Anything provider-specific
   (the `respose-code` misspelling, the response-shape matrix, the
   `clientsmsid` round-trip) lives in the concrete gateway. Consumers and the
   channel never know about it.
2. **Outbound events stay separate from application domains.**
   `SmsDispatchAccepted` and `SmsDispatchFailed` report recorded submission
   outcomes. Delivery callbacks store only the sanitized latest `dlr_payload`;
   they change no state/timestamps, emit no SDK/model events and invoke no
   application actions. The legacy `SmsDeliveryReported` class is retained
   without new default emission. See [events](docs/events.md).
3. **Two exception classes preserve input and outcome distinctions.**
   `SmsDispatchException` reports invalid input. `SmsProviderException` can
   also carry unknown acceptance or a local recording failure; it does not
   establish safe resend. Preserve its recovery diagnostics. Do not add
   another exception class without a demonstrated requirement.
4. **`sms_dispatches` records submission attempts.** Provider acceptance is
   distinct from handset delivery. Passive callback data is informational;
   a failed row is not proof of non-delivery or permission to retry.
5. **Tests are the contract.** Every behavioural change ships with at least
   one meaningful regression test. CI runs tests, static analysis, formatting,
   manifest validation and a dependency audit; it does not enforce a measured
   coverage threshold. Prepared workflows need actual execution evidence.

## Local setup

```bash
git clone git@github.com:Metasoftdevs/textsms-laravel-notification-sdk.git
cd textsms-laravel-notification-sdk
composer install
```

## Running checks

```bash
composer test      # Pest
composer phpstan   # static analysis (level 8)
composer pint-test # code style check (no writes)
composer pint      # code style fix
composer validate --strict
composer audit --locked # after install/update creates the local lock
```

Tests, static analysis, formatting, manifest validation and the applicable
dependency audit must pass before merge. Run the lowest/stable dependency
graphs for every runtime major claimed by the change. Record actual results;
a prepared workflow or widened Composer constraint is not compatibility proof.

## Branch naming

- `feat/<short-description>` for new features
- `fix/<short-description>` for bug fixes
- `docs/<short-description>` for documentation-only changes
- `chore/<short-description>` for tooling / dependency bumps

## Commit messages

Conventional Commits format: `type(scope): subject`. The `scope` is one of
`gateway`, `channel`, `events`, `webhook`, `normalizer`, `testing`, `docs`,
`ci`, or `deps`.

## Pull request checklist

- [ ] All CI checks green.
- [ ] New behaviour covered by a Pest regression test.
- [ ] If touching a provider's response shape, use provenance-labelled exact
      captured payloads where available and synthetic adversarial cases.
- [ ] Label synthetic fixtures and unverified live-contract assumptions; do not
      invent captures or expose credentials, recipients or message bodies.
- [ ] Documentation updated (`docs/*.md` and/or README).
- [ ] `CHANGELOG.md` updated under `[Unreleased]`.
- [ ] Backwards-incompatible changes flagged with `BC BREAK:` in the PR description.

## Reporting a security issue

Email security disclosures to **support@metasoftdevs.com** (or
**info@metasoftdevs.com**). Please do not open public issues for
security-sensitive material.
