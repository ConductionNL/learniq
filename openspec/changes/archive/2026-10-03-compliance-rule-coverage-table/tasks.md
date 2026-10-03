# Tasks: read every rule's coverage next to each other on one page

## 1. Server

- [x] 1.1 Implement `byRegulation` and its route with the department filter. Verify: PHPUnit comparing its totals with `byDepartment` on the same fixture; hydra gates 5, 7 and 30.

## 2. Frontend

- [x] 2.1a Build the coverage table widget and mount it. Verify: node --test `tests/unit-js/regulationCoverage.test.mjs` for sorting and the empty in-scope row.
- [x] 2.1b Playwright flow: open /compliance and read the table (live check, owed). Done live on the dev instance 2026-10-03 (learniq 86826b30): a compliance officer opens /compliance and reads "Coverage per rule"; a regulation with nobody in scope shows 0 in scope with no percentage or state; `?department=` narrows the figures (LivePass excused 1, Logistics 0); a learner gets 403 from the by-regulation figures. Evidence: ~/memcap-work/build-all/livepass/learniq/compliance-rule-coverage-table/lane4/ and RESULT-lane4.md. Not seen live: four rules with learners in scope and a RAG state (the instance holds one published regulation with nobody in scope); that part rests on 1.1 and 2.1a.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [x] 3.2 Set row `comp-all-rules-one-page` to built and archive the change. Verify: parity_verify --strict.

