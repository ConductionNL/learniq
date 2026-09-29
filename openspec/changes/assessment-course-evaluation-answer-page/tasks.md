# Tasks: a learner answers a course evaluation

## 1. Learner side

- [ ] 1.1 Add a route or view that lists the caller's open invitations. Verify: PHPUnit for own only and closed campaigns excluded; hydra gates 5, 7 and 30.
- [ ] 1.2 Build the My evaluations page and the answer form from `questions`. Verify: vitest for each question kind; Playwright flow answer and see it disappear.

## 2. Staff side

- [ ] 2.1 Add the campaign result figures with the five-response rule. Verify: PHPUnit for 3 and 6 responses.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set row `ass-run-a-survey` to built and archive the change. Verify: parity_verify --strict.

