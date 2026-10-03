# Tasks: read every rule's coverage next to each other on one page

## 1. Server

- [x] 1.1 Implement `byRegulation` and its route with the department filter. Verify: PHPUnit comparing its totals with `byDepartment` on the same fixture; hydra gates 5, 7 and 30.

## 2. Frontend

- [x] 2.1a Build the coverage table widget and mount it. Verify: node --test `tests/unit-js/regulationCoverage.test.mjs` for sorting and the empty in-scope row.
- [ ] 2.1b Playwright flow: open /compliance and read the table (live check, owed).

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `comp-all-rules-one-page` to built and archive the change. Verify: parity_verify --strict.

