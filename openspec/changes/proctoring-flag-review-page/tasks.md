# Tasks: review proctoring flags in one queue

## 1. Guard

- [x] 1.1 `lib/Listener/ProctoringFlagReviewGuard.php`: pre-write veto on create and update of `proctoring-session` (copy the shape of `lib/Listener/ElectiveSignUpRules.php`). Match flags on `flagId`; rules as in design D2; stamp `reviewedBy` and `reviewedAt`. Register it beside the other assessment listeners in `lib/AppInfo/Registrar/`. Built: the rules live in `lib/Proctoring/FlagReview.php` (pure), the listener in `lib/Listener/ProctoringFlagReviewGuard.php`, registered in `EvidenceFreezeListenerRegistrar`.
- [x] 1.2 PHPUnit `tests/Unit/Listener/ProctoringFlagReviewGuardTest.php`: learner append accepted, learner decision refused, learner removal refused, staff decision stamped, second decision refused, admin and system writes unchecked. Build the real OpenRegister event class, not a mock event.

## 2. Page

- [ ] 2.1 `src/manifest.d/learning.json`: page `ProctoringReviewQueue` (`/assessments/proctoring/review`, `type: custom`, component `ProctoringReviewQueue`) and a menu child "Proctoring review" under Assessments with `visibleIf` on `instructor` and `compliance-officer`; add a header link on `ProctoringSessions`. Verify: `npm run check:manifest` and `npm run check:menu-role-gates`.
- [ ] 2.2 `src/views/ProctoringReviewQueue.vue`: card heading "Session for {assessment}", learner display name, provider, open count; flag kind in words (design D5), time and minutes in (D4); decided flags show "Allowed by {name} on {date}" or "Annulled by ..."; footer sentence from the board. Send only the decision; the server stamps the rest.
- [ ] 2.3 English and Dutch strings from the design. Verify: `npm run check:l10n`.
- [ ] 2.4 Fix the `@spec` tags in the view (they point at a retrofit change) to `openspec/specs/assessment/spec.md#requirement-staff-reach-the-proctoring-flag-review-page`.

## 3. Tests and close out

- [ ] 3.1 Playwright `tests/e2e/proctoring-review.spec.ts`: seed a native test-mode session with two pending flags; as an instructor open the page from the menu, allow one, annul the other, read "Allowed by"; as a learner see no entry. Tag the scenarios with `@e2e`.
- [ ] 3.2 One live check as a learner: PATCH a decision on your own session and read the refusal.
- [ ] 3.3 Set row `ass-review-proctoring-flags` to `built`, `learniq: yes`, `reachedOn: "/assessments/proctoring/review"`; archive this change into `openspec/specs/assessment`.
