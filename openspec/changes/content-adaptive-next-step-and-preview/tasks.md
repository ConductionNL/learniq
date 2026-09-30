# Tasks: send a learner to a different next step depending on how they did, and preview a course as a learner

## 1. Adaptive next step

- [x] 1.1 Add `nextStepRules` and `defaultNextLessonId` to `Lesson` and validate same-course targets. Verify: `npm run check:register`, PHPUnit for the same-course rule. Done: `LessonNextStepRegisterTest` (Opis against the shipped fragment), `LessonNextStepGuardTest`.
- [x] 1.2 Implement `NextStepResolver` and its route. Verify: PHPUnit for fail, pass, no rule and a rule that references another learner's result (refused). Done: `NextStepResolverTest` (real LessonReleaseEvaluator over RegisterFaithfulStore), `LessonNextStepControllerTest`.
- [x] 1.3a Add the next step editor to the composer and use the resolver in the player. Verify: node tests for the editor's rule shape (`tests/unit-js/lessonPreview.test.mjs`; the repo runs `node --test`, not vitest).
- [ ] 1.3b Playwright flow fail then pass (CI only).

## 2. Preview

- [x] 2.1a Add preview mode to the player with the simulated result control and the banner, and make completion, result and xAPI writers refuse writes in preview. Verify: PHPUnit (`PreviewWriteGuardTest`, `LessonNextStepControllerTest` 403 and preview) and node tests that every player write carries the preview header (`lessonPreview.test.mjs`).
- [ ] 2.1b Playwright flow walk a course and read completions (CI only).

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set rows `cont-adaptive-next-step` and `cont-preview-as-learner` to built and archive the change. Verify: parity_verify --strict.

