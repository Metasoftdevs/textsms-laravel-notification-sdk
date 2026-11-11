# T-08A — Archive exclusions

**Date:** 2026-11-01 (Africa/Nairobi)
**Baseline:** `main` @ `f6b76272d2d48e34b6e64956dfadcf7034d1f5ca`
**Directive:** `.docs/dev/directives/2026-11-01-first-wave-execution-directive.md` §3
**Review constraint:** AR-08 (`.docs/dev/reviews/codex/2026-11-01-contextualization-and-adversarial-plan-review.md`)
**Status:** implemented locally; not committed.

## Intent

Internal engineering material must never ship in the Composer distribution
archive. Before this change, `.docs/`, `.agents/`, `.claude/`, and `pint.json`
had no `export-ignore` attribute. If those directories were committed, every
`composer install` would receive internal reviews, agent directives, and local
agent settings.

## Change

File changed: `.gitattributes` only.

| Rule | Action |
|---|---|
| `/pint.json export-ignore` | Added (style configuration is a development tool). |
| `/.docs export-ignore` | Added. |
| `/.agents export-ignore` | Added. |
| `/.claude export-ignore` | Added. |
| `/.php-cs-fixer.dist.php export-ignore` | Removed (the file does not exist; the project uses Pint). |

All other distribution exclusions are unchanged. No runtime path gained an
exclusion.

## Evidence (2026-11-01)

### 1. Attribute output

`git check-attr export-ignore -- .docs .agents .claude pint.json .php-cs-fixer.dist.php`

```text
.docs: export-ignore: set
.agents: export-ignore: set
.claude: export-ignore: set
pint.json: export-ignore: set
.php-cs-fixer.dist.php: export-ignore: unspecified
```

Before the edit, the first four reported `unspecified`.

Directory rules exclude the whole directory from `git archive`. A child path
such as `.docs/dev/x.md` still reports `unspecified` from `git check-attr`;
that is expected Git behaviour (AR-08), not a gap.

### 2. Real repository archive — tracked content

`git archive --worktree-attributes --format=tar HEAD | tar -t`

Observed: the archive contains `LICENSE`, `README.md`, `composer.json`,
`config/textsms.php`, the migration stub, `routes/webhooks.php`, and every
file under `src/`. It does not contain `pint.json`, `docs/`, `tests/`,
`examples/`, `.github/`, `CHANGELOG.md`, `CONTRIBUTING.md`,
`phpstan.neon.dist`, `phpunit.xml.dist`, `.gitattributes`, or `.gitignore`.

Limit: `.docs/`, `.agents/`, and `.claude/` are untracked, so this archive
cannot show their exclusion.

### 3. Synthetic archive — internal directories (labelled synthetic)

Method: an isolated temporary Git repository in the session scratch
directory. It received a copy of the new `.gitattributes` and harmless
sentinel files at the same relative paths as the real layout. `git add -A`
then `git write-tree` produced tree `ae2d19d7…`; **no commit was created**.
`git archive --worktree-attributes` listed the tree. The scratch repository
was deleted afterwards. This repository was neither staged nor committed.

| Sentinel path | In archive? |
|---|---|
| `.docs/dev/reviews/sentinel.md`, `.docs/dev/directives/sentinel.md` | No |
| `.agents/sentinel.md` | No |
| `.claude/settings.json` | No |
| `pint.json` | No |
| `docs/sentinel.md`, `tests/Feature/SentinelTest.php` | No |
| `src/Sentinel.php`, `config/textsms.php`, `database/migrations/…php`, `routes/webhooks.php` | Yes |
| `composer.json`, `LICENSE`, `README.md` | Yes |

### 4. Whitespace

`git diff --check -- .gitattributes` → clean.

No permanent PHP test was added; the directive does not require one for this
metadata change.

## Remaining scope and gates

- **Release gate (T-21):** inspect the archive of the exact release tree once
  the internal directories are committed (if D6 allows that). The synthetic
  evidence above is preliminary.
- **T-08B (open, blocked on D6):** the test-recipient reference in tracked
  documentation and git history, and whether internal documents belong in a
  public repository. Archive exclusion does **not** hide tracked files or their
  history on a public repository host. No recipient reference was edited and no
  history was changed by this task.
