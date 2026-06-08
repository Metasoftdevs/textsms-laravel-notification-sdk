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
2. **The gateway emits events, never reaches into application domain.**
   Receipt-side reconciliation, audit logging, resend logic — all live in
   consumer-side listeners, not in this package. See `docs/events.md`.
3. **Two exception classes split caller-fault from provider-fault.**
   `SmsDispatchException` for bad input; `SmsProviderException` for everything
   the provider does. Don't add a third without strong justification.
4. **`sms_dispatches` is the source of truth.** Any caller-side denormalisation
   (e.g. a receipt's `sms_status` column) is mirrored from this table via an
   event listener.
5. **Tests are the contract.** Every behavioural change ships with at least
   one regression test. Coverage is enforced by CI.

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
```

All three must be green before a PR is merged.

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
- [ ] If touching a provider's response shape, the test uses the **exact** wire payload from a Postman capture or live probe.
- [ ] Documentation updated (`docs/*.md` and/or README).
- [ ] `CHANGELOG.md` updated under `[Unreleased]`.
- [ ] Backwards-incompatible changes flagged with `BC BREAK:` in the PR description.

## Reporting a security issue

Email security disclosures to **support@metasoftdevs.com** (or
**info@metasoftdevs.com**). Please do not open public issues for
security-sensitive material.
