## Scope

`SessionChangeNoticeHandler` already materialises `affectedLearnerIds`/`affectedParentIds`/`changedAt` onto a `Session` on `cancel`/`substitute-teacher`/`substitute-teacher-in-progress` — exactly the write side of the notification recipient shape the `timetabling` capability spec already documents. But `Session` declared no `x-openregister-notifications` block, so nobody was ever actually notified. This change adds `Session.x-openregister-notifications.rosterChanged` (one JSON block, no PHP change) and a register-JSON assertion test.

## Evidence

`learniq-defect-triage.md`, entry 3: "Session declares no notifications, so roster-change recipients are computed and never used" — read directly from `SessionChangeNoticeHandler`'s own class docblock, which documents the intent, next to the `Session` schema, which never declared the corresponding block. The existing `openspec/specs/timetabling/spec.md` requirement "Cancellation or substitution notifies affected learners and parents" already specified this exact `transition`-triggered, `kind:field`-recipient shape — this PR is a spec conformance fix, and additionally extends the requirement's action list to include `substitute-teacher-in-progress` (the in-progress counterpart the handler's own `WATCHED_ACTIONS` constant already reacts to, which the original spec text omitted).

## M1 rows

Per the triage's summary table, this recovers row `11.5` ("Roster changes pushed to learners and parents") fully from partial to yes — its only cited gap was this one.

## What was verified (exit codes)

- `python3 -c "json.load(...)"` — register is valid JSON after the edit
- `npm run check:json-strict` — exit 0
- `npm run check:register` — exit 0 (2 register files, 0 warnings)
- `php -l tests/Unit/Settings/SessionRosterNotificationRegisterTest.php` — exit 0
- `vendor/bin/phpunit --filter SessionRosterNotificationRegisterTest` — exit 0 (5/5, 12 assertions)
- `npm run lint` — exit 0 (19 pre-existing warnings, 0 errors)
- `npm run format` — exit 0
- `TMPDIR=$PWD/.tmp COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` (via semaphore) — `ALL CHECKS PASSED`: 1079 tests / 4802 assertions / 5 skipped, lint/phpcs/phpmd/psalm/phpstan all 0 errors
- `bash hydra/scripts/run-hydra-gates.sh --scope-to-diff --base origin/development` — 2 gates failed, both inherited/unrelated to the actual register content: `gate-112 newman-reach` (pre-existing, same as PR #907); `gate-53 effective-manifest-crossref` — the checker itself throws `ReferenceError: require is not defined in ES module scope` (an ESM/CommonJS bug in `hydra-gates/scripts/lib/build_effective_manifest.js`, not a finding about this PR's JSON — `check:json-strict`/`check:register`/plain `json.load` all confirm the register is well-formed). `gate-68 duplicate-index-pages` did not run (same broken checker as PR #907, unrelated).

## phpcs note on the new test file

`vendor/bin/phpcs` on the new test file reports 10 findings, all the same "named parameters" sniff finding that fires identically on every existing `*RegisterTest.php` in this repo (verified by running the identical command against `ReportCardComposerRegisterTest.php`) — and `phpcs.xml`'s only configured `<file>` is `lib`, so `composer phpcs` (part of `check:strict` above) never lints `tests/` at all. Zero NEW findings on lines this PR touches.

## Inherited findings

Hydra `gate-112` (newman-reach) and the `gate-53`/`gate-68` broken-checker failures are pre-existing/whole-app or tooling issues, not introduced by this diff — none were fixed here per the fleet's inherited-debt policy.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
