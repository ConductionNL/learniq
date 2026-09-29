# Tasks: show the WCAG 2.1 AA evidence on request

## 1. Register and guard

- [ ] 1.1 Add the schema and the seeded criterion list. Verify: `npm run check:register`, PHPUnit that the seed holds 50 unique criteria.
- [ ] 1.2 Extend the publish guard: a fail needs a limitation. Verify: PHPUnit for the denial and the pass.

## 2. Page and export

- [ ] 2.1 Add the conformance table and edit dialog to the statement page. Verify: vitest for the fail-needs-limitation prompt; Playwright flow record a result.
- [ ] 2.2 Add the public export route (JSON, CSV) with rate limiting. Verify: PHPUnit that no personal field is present; hydra route-auth gate.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `gov-wcag-aa` to built and archive the change. Verify: parity_verify --strict.

