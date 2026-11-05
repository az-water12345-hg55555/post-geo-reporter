# Code version and update practice

GitHub holds the reviewed code history. Local clones are working copies.

## Changes
1. Inspect the current remote, branch, working tree, and repository instructions.
2. Create a focused fix/, feature/, or chore/ branch.
3. Make small commits and run relevant checks.
4. Open a pull request with behaviour, validation, and release implications.
5. Merge after reviewing the diff and passing the applicable checks.

Use patch versions for compatible fixes, minor versions for compatible features, and major versions for breaking changes. Retain established numbering. Do not tag a new application release for documentation-only setup.

## Releases
Align the plugin header, PGR_VERSION, readme version, and changelog before tagging vMAJOR.MINOR.PATCH. Record the exact commit, checks, installation package, and any migration. Syntax CI is a minimum check; tracking, reporting, permissions, and upgrades also need relevant WordPress validation.

## Deployment and recovery
A merged pull request is not a WordPress deployment. Record the installed version separately. Retain the previous installation package. Back up the database before schema changes. Revert code through a new commit; recover data separately where necessary.

## Repository settings
Use main as the stable branch. Require the PHP syntax check and resolved review conversations where GitHub settings and plan permit. Do not require another reviewer when the owner is the only maintainer. This document describes the desired settings; it does not itself enforce branch protection.

## Initial inspection — 6 November 2026 (Sydney)
The inspected baseline was ba026d076bb8fdbad08074390f1a5acccaf31c97. Existing version metadata differs: plugin header 1.5.2, PGR_VERSION 1.5.0, and readme 1.0.0. Reconcile these during the next application release after checking release intent. This setup does not change application behaviour or claim a deployed version.
