## 1. Wire the existing checker into npm scripts

- [x] 1.1 In `package.json`'s `scripts` block, add `"check:l10n": "node tests/l10n/check-l10n-parity.js"`.
- [x] 1.2 Update `"check:specs"` to `"npm run check:json-strict && npm run check:manifest && npm run
      check:register && npm run check:l10n"` so a single local command (already the one contributors and
      CI run) also catches locale drift.

## 2. Wire it into CI

- [x] 2.1 `.github/workflows/spec-validation.yml`'s `Validate specs (json-strict + manifest + register)`
      step already runs `npm run check:specs` (line 40) — no new step is needed once 1.2 lands; update the
      step's `name:` to `Validate specs (json-strict + manifest + register + l10n)` and the file's header
      comment (lines 4-11) to document the new `check:l10n` bullet, matching the existing bullet style.

## 3. Decide the initial-fail sequencing (BLOCKING decision for whoever applies this change)

- [x] 3.1 Because `check:l10n` currently fails (83 missing keys across 35 locales, verified at HEAD), landing
      1.1/1.2/2.1 as-is will turn `check:specs` red on every subsequent PR. Before merging, choose one:
      (a) file the translation-backfill follow-up issue first and merge this gate only once that lands, or
      (b) temporarily scope `check-l10n-parity.js` to fail only on *new* missing keys introduced by the diff
      (i.e. a coverage ratchet, matching the pattern already used by the app's Hydra gates per ADR-020)
      while leaving the 83 pre-existing gaps as tracked debt. Record the decision in this task before
      implementing.
- [x] 3.2 Implement whichever sequencing was chosen in 3.1.

## 4. Traceability

- [x] 4.1 Add a `@spec openspec/changes/wire-l10n-parity-ci-gate/tasks.md#task-N` reference in a comment
      near the modified `check:specs` line in `package.json` (matching the app's existing convention of
      citing specs from tooling config where practical).
- [x] 4.2 Run `npm run check:specs` locally and confirm the new `check:l10n` step actually executes (not
      just that the composite command exits 0/1 — confirm the l10n step's own PASS/FAIL line appears in
      the output).
- [x] 4.3 Run `openspec validate wire-l10n-parity-ci-gate --strict` and resolve any errors.

## Evidence and decisions (round 5, lane r5-security)

- 3.1 decision: **(b), a ratchet.** Measured on development 2026-09-28 the gap is far past the proposal's
  83 keys: 2,145 English keys, 33 locales translate 478 (1,667 missing each), `nl` lacks 69 (frontend)
  and 77 (backend), and `ca`, `lb`, `rm` have no file. A full gate would be red on every PR, and the
  fleet convention is en plus nl for every new key, other locales following by AI translation (D24).
  So the strict locale `nl` may gain no new gap, every other required locale may not lose a translation,
  and the down path fails too (lesson: a ratchet that passes quietly on improvement hands the margin back).
- 3.2: `--ratchet` and `--update` modes in `tests/l10n/check-l10n-parity.js`; the no-argument full-parity
  mode is unchanged. Baseline `l10n/.l10n-parity-baseline.json` (14 KB). Mutation-tested on a copy:
  new English key without nl, exit 1 naming it; with nl, exit 0; `de` loses one, exit 1; `nl` closes a
  gap, exit 1; `de` gains one, exit 1; no baseline, exit 1.
- 1.1, 1.2: `check:l10n` in `package.json`, appended to `check:specs`.
- 2.1: `.github/workflows/spec-validation.yml` step renamed and header bullet added. `check:specs` is
  also in `code-quality.yml`'s `frontend-checks`, so both workflows run it.
- 4.1: JSON has no comments, so the `@spec` reference sits in the checker's header and the workflow bullet.
- 4.2: `npm run check:specs` output shows the `check:l10n` step's own `l10n-parity: OK` line (PR body).
- 4.3: `openspec validate --strict` clean. The second spec scenario was reworded to the ratchet semantics.
