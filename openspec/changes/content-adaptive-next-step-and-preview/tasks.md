# Tasks: send a learner to a different next step depending on how they did, and preview a course as a learner

## 1. Adaptive next step

- [ ] 1.1 Add `nextStepRules` and `defaultNextLessonId` to `Lesson` and validate same-course targets. Verify: `npm run check:register`, PHPUnit for the same-course rule.
- [ ] 1.2 Implement `NextStepResolver` and its route. Verify: PHPUnit for fail, pass, no rule and a rule that references another learner's result (refused).
- [ ] 1.3 Add the next step editor to the composer and use the resolver in the player. Verify: vitest for the editor; Playwright flow fail then pass.

## 2. Preview

- [ ] 2.1 Add preview mode to the player with the simulated result control and the banner, and make completion, result and xAPI writers refuse writes in preview. Verify: PHPUnit and vitest that no writer is called; Playwright flow walk a course and read completions.

## 3. Close out

- [ ] 3.1 Add strings to every shipped locale. Verify: `npm run test:l10n`.
- [ ] 3.2 Set rows `cont-adaptive-next-step` and `cont-preview-as-learner` to built and archive the change. Verify: parity_verify --strict.

