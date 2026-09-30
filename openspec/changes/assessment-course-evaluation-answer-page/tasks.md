# Tasks: a learner answers a course evaluation

## 1. Learner side

- [x] 1.1 Add a route or view that lists the caller's open invitations. Verify: PHPUnit for own only and closed campaigns excluded; hydra gates 5, 7 and 30. Done: `CourseEvaluationAnswerServiceTest` (RegisterFaithfulStore, the real eligibility guard and submitted handler behind the transition, Opis against the shipped response schema), `CourseEvaluationAnswerControllerTest`.
- [x] 1.2a Build the My evaluations page and the answer form from `questions`. Verify: node tests for each question kind (`tests/unit-js/evaluationAnswers.test.mjs`; the repo runs `node --test`, not vitest).
- [ ] 1.2b Playwright flow answer and see it disappear (CI only).

## 2. Staff side

- [x] 2.1 Add the campaign result figures with the five-response rule. Verify: PHPUnit for 3 and 6 responses. Done: `CourseEvaluationAnswerServiceTest::testSmallGroupsAreProtected`.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`. Done: en and nl, `check:l10n-js` and `check:schema-l10n`.
- [ ] 3.2 Set row `ass-run-a-survey` to built and archive the change. Verify: parity_verify --strict.

