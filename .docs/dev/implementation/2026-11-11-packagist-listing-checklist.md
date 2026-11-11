# Packagist listing checklist

**Prepared:** 2026-11-11, Africa/Nairobi.
**Package:** `metasoftdevs/textsms-laravel-notification-sdk`.
**First version:** `v1.0.0-rc1`, commit `e57ee40f9168b12d68d1898834019994ce74df46`.
**Status:** owner published; public RC installation and live submissions verified.
Owner confirms all four handset receipts; automatic-update setup remains unverified.
This is the short listing
pathway after the completed [release verification](2026-11-11-commit-ci-and-tag-verification.md).

## Already complete

- [x] Implementation and release verification committed and pushed through `505a30a`.
- [x] Complete remote CI passed on both the tagged commit and final main:
  twelve test-matrix jobs plus PHPStan and Pint.
- [x] Annotated RC tag pushed; actual GitHub distribution verified with28
  runtime files, all matching their committed bytes.
- [x] Tagged ZIP installed and booted successfully on Laravel12 and13.
- [x] Package metadata validated; version comes from the tag, without a
  hard-coded Composer version field.
- [x] Owner accepts retaining the historical recipient. The agreed
  post-publication live SMS verification is complete. Other optional polishing
  is deferred.

## List the package

1. [x] **Repository administrator:** make
   [the repository](https://github.com/Metasoftdevs/textsms-laravel-notification-sdk)
   public. Confirm the repository and RC tag are accessible while signed out.
   Unauthenticated access now returns HTTP200; this testing task did not change visibility.
2. [x] **Packagist maintainer:** sign in with an account entitled to publish
   under `metasoftdevs`. This vendor already exists; GitHub organization
   membership alone does not establish Packagist maintainer rights.
3. [x] Owner completed publication at [the package listing](https://packagist.org/packages/metasoftdevs/textsms-laravel-notification-sdk) using:

   ```text
   https://github.com/Metasoftdevs/textsms-laravel-notification-sdk
   ```

4. [x] Confirm Packagist imports the exact package name, `1.0.0-rc1` and
   `dev-main`. Check the RC points to `e57ee40`, with correct requirements,
   license, source and distribution links. Resolve any actual import error.
5. [ ] Enable automatic updates through the Packagist GitHub integration,
   including access to the MetaSoftDevs organization. Confirm the package
   reports automatic synchronization enabled.

## Verify the public listing, then test live

- [x] In the designated Laravel12 demo application using public Packagist, with
  no local path/package/VCS override for this SDK, run:

  ```bash
  composer require "metasoftdevs/textsms-laravel-notification-sdk:1.0.0-rc1"
  ```

  Pinning the RC explicitly avoids changing application-wide stability settings.
- [x] Confirm version/source, package discovery, commands and migration/config
  publication. Use the [README setup](../../../README.md) and publish the
  creation migration once. Respect the application's morph key configuration.
- [x] Configure authorized TextSMS credentials and approved recipients in
  that test application. Run the agreed single-send and two-message bulk tests;
  verify client-reference acceptance, bulk echo and provider-ID correlation.
  Keep sanitized evidence. Do not infer those live results from the existing fakes.
- [x] Owner confirms both handsets received both test messages; provider acceptance
  alone does not establish handset delivery.
- [x] Record the public package URL, install result and live submission results
  in [live validation](2026-11-11-live-packagist-validation.md).
  Keep RC/stable status accurate; a later stable release uses its own new tag.

## Current checks and references

The earlier draft observed a private repository and a404 public package endpoint.
The owner subsequently published it. Current unauthenticated repository access
returns200, public metadata exposes the RC, and actual Packagist installation
and four authorized live submissions passed. Existing release tags/source are
unchanged; no account/visibility mutation was performed by this testing task.

- [Packagist submission and vendor permissions](https://packagist.org/about#how-to-submit-packages)
- [Packagist automatic updates](https://packagist.org/about#how-to-update-packages)
- [Composer version and stability constraints](https://getcomposer.org/doc/articles/versions.md#stability-constraints)
