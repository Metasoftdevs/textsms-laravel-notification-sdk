# T-08B — Current-file redaction and repository disclosure

**Date:** 2026-11-11, Africa/Nairobi. **Owner:** Codex.
**Status:** current target recipient redacted; internal-folder disclosure
resolved by the owner; owner accepts retaining the recipient in Git history.

The owner explicitly permits committing `.docs/` and `.agents/`. Distribution
exclusions remain: permission to include Git documentation does not require
shipping it to Composer consumers. `.claude/settings.json` was independently
inspected and contains only six local shell permission allowances. Under the
owner's condition it remains local, and `.claude/` was added to `.gitignore`.
No permission values are copied to this record.

The known recipient was replaced with an anonymized placeholder in the three
current paths that contained it: the historical package development plan and
two internal review documents (six occurrences total). Evidence/test dates
remain historical. Independent path-only scan established the original scope;
the current tree is checked again before handoff.

The recipient also occurs in the reachable history of both `e1db5df` and
`f6b7627`, in `docs/package-development-plan.md`. Redacting current files does
not change those commits. An async owner question requests explicit local
history cleanup authorization, identifying both changed commit IDs and
excluding any remote force-push. The owner subsequently answered that the
number can remain in history. No cleanup or history mutation is required or
authorized; the current redactions remain. This supersedes the pending question.

This is the known-recipient/disclosure task, not a claim that every document,
Git object or external log has passed a comprehensive privacy audit. Final
release-tree/history review remains necessary. No source, provider request,
deployment secret rotation, commit or repository visibility change occurred.

Final November11 recheck derived the one removed recipient from the current
development-plan diff without printing its value. It found zero matches and
zero unreadable files across current Markdown/PHP/JSON/YAML/text/Neon/XML
files, excluding `.git`, vendor, build, coverage and local `.claude` material.
The ignored script is `build/nov11-known-recipient-check.py`. This pass does
not alter or clear the historical-commit finding above.
