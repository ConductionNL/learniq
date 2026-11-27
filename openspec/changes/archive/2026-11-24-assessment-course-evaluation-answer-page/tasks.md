# Tasks: a learner answers a course evaluation

## 1. Learner side

- [x] 1.1 Add a route or view that lists the caller's open invitations. Verify: PHPUnit for own only and closed campaigns excluded; hydra gates 5, 7 and 30. Done: `CourseEvaluationAnswerServiceTest` (RegisterFaithfulStore, the real eligibility guard and submitted handler behind the transition, Opis against the shipped response schema), `CourseEvaluationAnswerControllerTest`.
- [x] 1.2a Build the My evaluations page and the answer form from `questions`. Verify: node tests for each question kind (`tests/unit-js/evaluationAnswers.test.mjs`; the repo runs `node --test`, not vitest).
- [x] 1.2b Playwright flow answer and see it disappear (CI only).
  - Written: `tests/e2e/spec-coverage/course-evaluation-answering.spec.ts` (own learner account, open campaign and invitation; answer, gone after reload, invitation `hasResponded`, a second answer 409). Written, runs in nightly (not verified live here).

## 2. Staff side

- [x] 2.1 Add the campaign result figures with the five-response rule. Verify: PHPUnit for 3 and 6 responses. Done: `CourseEvaluationAnswerServiceTest::testSmallGroupsAreProtected`.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`. Done: en and nl, `check:l10n-js` and `check:schema-l10n`.
- [x] 3.2 Set row `ass-run-a-survey` to built and archive the change. Verify: parity_verify --strict.
  - Done 24 Nov 2026: the row is `built`, `learniq: yes`, reached on My evaluations; both requirements are in the new main spec `openspec/specs/course-evaluation-answering` and the `@spec` tags point there.

