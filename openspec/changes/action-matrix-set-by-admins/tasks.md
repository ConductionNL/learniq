# Tasks: action-matrix-set-by-admins

## 1. Tags

- [x] 1.1 Add `@spec openspec/specs/action-authorization/spec.md#requirement-every-guarded-action-is-decided-by-the-action-matrix` to `lib/Service/ActionAuthService.php` and `lib/actions.seed.json` (`$comment`).
- [x] 1.2 Add `@spec openspec/specs/action-authorization/spec.md#requirement-an-administrator-changes-the-matrix-without-a-developer` to `ActionMatrixController::getMatrix()`, `::setMatrix()` and `src/components/admin/ActionAuthMatrix.vue`.

## 2. Checks

- [x] 2.1 `tests/Unit/Controller/ActionMatrixControllerTest.php`: a saved matrix takes effect on the next `requireAction()` call, without a restart. Verify: `composer test:unit -- --filter ActionMatrixControllerTest`.
  - `testASavedMatrixTakesEffectOnTheNextGuardedCall`: the real `CallerActionCheck` refuses a coordinator, the controller saves a matrix granting `coordinators`, and the very next check lets them through, through the same service.
- [ ] 2.2 Playwright `tests/e2e/spec-coverage/action-authorization.spec.ts`: an admin ticks a group for one action, saves, reloads and sees the tick kept.

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change and repoint both rows to `openspec/specs/action-authorization`.
