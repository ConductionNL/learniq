# Tasks: send a learner to a different next step depending on how they did, and preview a course as a learner

## 1. Adaptive next step

- [x] 1.1 Add `nextStepRules` and `defaultNextLessonId` to `Lesson` and validate same-course targets. Verify: `npm run check:register`, PHPUnit for the same-course rule. Done: `LessonNextStepRegisterTest` (Opis against the shipped fragment), `LessonNextStepGuardTest`.
- [x] 1.2 Implement `NextStepResolver` and its route. Verify: PHPUnit for fail, pass, no rule and a rule that references another learner's result (refused). Done: `NextStepResolverTest` (real LessonReleaseEvaluator over RegisterFaithfulStore), `LessonNextStepControllerTest`.
- [x] 1.3a Add the next step editor to the composer and use the resolver in the player. Verify: node tests for the editor's rule shape (`tests/unit-js/lessonPreview.test.mjs`; the repo runs `node --test`, not vitest).
- [x] 1.3b Live flow on the shared dev instance (2 Oct, learniq 1197793c): as the learner, next step is the default with no attempt, the remedial lesson after a graded 3, the advanced lesson after a graded 8; the player shows "Go to LP advanced lesson". Evidence: `~/memcap-work/build-all/livepass/learniq/content-adaptive-next-step-and-preview/RESULT.md` (results written through the API, no quiz UI).

## 2. Preview

- [x] 2.1a Add preview mode to the player with the simulated result control and the banner, and make completion, result and xAPI writers refuse writes in preview. Verify: PHPUnit (`PreviewWriteGuardTest`, `LessonNextStepControllerTest` 403 and preview) and node tests that every player write carries the preview header (`lessonPreview.test.mjs`).
- [x] 2.1b Live flow (2 Oct, browser): preview as learner with simulated score 3, walk two lessons and mark both complete: no write request, completion and xAPI counts unchanged; a create with `X-Learniq-Preview: 1` answers 422 and the same create without it 201. Evidence: same RESULT.md.

## 3. Close out

- [x] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [x] 3.2 Set rows `cont-adaptive-next-step` and `cont-preview-as-learner` to built and archive the change. Verify: parity_verify --strict.

