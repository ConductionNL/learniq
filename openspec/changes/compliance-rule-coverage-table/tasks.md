# Tasks: read every rule's coverage next to each other on one page

## 1. Server

- [ ] 1.1 Implement `byRegulation` and its route with the department filter. Verify: PHPUnit comparing its totals with `byDepartment` on the same fixture; hydra gates 5, 7 and 30.

## 2. Frontend

- [ ] 2.1 Build the coverage table widget and mount it. Verify: vitest for sorting and the empty in-scope row; Playwright flow open /compliance and read the table.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `comp-all-rules-one-page` to built and archive the change. Verify: parity_verify --strict.

