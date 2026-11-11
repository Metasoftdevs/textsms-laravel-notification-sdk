# Commit Directives

Apply these rules to repository commits unless the project owner gives a more specific instruction.

## Authorization and scope

- Create a commit only when the project owner authorizes it.
- Stage explicit paths. Do not use broad staging when unrelated work is present.
- Review `git status`, the staged diff and the staged file list before committing.
- Keep implementation, tests and their required configuration together. Use a separate documentation commit when that gives reviewers a clearer boundary.
- Do not include secrets, runtime logs, generated receipts, caches or temporary verification files.

## Message format

- Use a Conventional Commit subject: `<type>(<scope>): <imperative summary>`.
- Describe the concrete behavior and relevant design decision in the body.
- End the body with `Files modified:` followed by every committed path.
- Do not add AI attribution, generated-by text or AI co-author trailers.

## Verification

- Run the focused tests required by the affected domain.
- Run `vendor/bin/pint --dirty` for changed PHP files and `git diff --check` before committing.
- Record only tests and probes that actually ran, including their dates when the evidence predates the commit.
- Confirm the working tree contains only the intended remaining work after each commit.

## Remote operations

- Push only when the project owner authorizes it.
- Do not force-push, amend or rewrite published history without explicit authorization.
