# Repository working instructions

Use GitHub as the source of truth for code changes. Inspect the current branch, working tree, existing instructions, and recent commits before editing. Preserve unrelated user work.

Use a short-lived fix/, feature/, or chore/ branch and focused commits. Open a pull request describing the problem, resulting behaviour, validation, and material limitations. Run relevant checks before merging. Do not force-push main or overwrite history.

Keep secrets, visitor exports, IP data, WordPress databases, logs, and generated plugin ZIP files out of Git. Use sanitised examples for fixtures.

Preserve established plugin versions. For a release, align the plugin header, PGR_VERSION, readme version, release tag, and changelog. Database schema versions change only for schema changes. Never invent a historical release or claim an untested baseline is production-verified.

GitHub changes do not install the plugin in WordPress. Treat deployment as a separate authorised task. Record the installed version and exact release commit; preserve the prior package and back up data before database migrations. A code revert does not reverse a database migration.
