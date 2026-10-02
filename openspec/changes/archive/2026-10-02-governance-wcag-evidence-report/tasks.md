# Tasks: show the WCAG 2.1 AA evidence on request

## 1. Register and guard

- [x] 1.1 Add the schema and the seeded criterion list. Verify: `npm run check:register`, PHPUnit that the seed holds 50 unique criteria.
- [x] 1.2 Extend the publish guard: a fail needs a limitation. Verify: PHPUnit for the denial and the pass.

## 2. Page and export

- [x] 2.1a Add the conformance table and edit dialog to the statement page. Verify: node --test `tests/unit-js/conformance.test.mjs` for the fail-needs-limitation prompt and the saved payload.
- [x] 2.1b Live flow on the shared dev instance (2 Oct, learniq 1197793c, browser): record 1.1.1 pass on the published statement (summary moves to 1 pass, 49 not tested), then download the evidence signed out as JSON and CSV (200, no user field). Evidence: `~/memcap-work/build-all/livepass/learniq/governance-wcag-evidence-report/RESULT.md`.
- [x] 2.2 Add the public export route (JSON, CSV) with rate limiting. Verify: PHPUnit that no personal field is present; hydra route-auth gate.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [x] 3.2 Set row `gov-wcag-aa` to built and archive the change. Verify: parity_verify --strict.

