# Tasks: assign mandatory training automatically from job and role data in the HR system

## 1. Register

- [ ] 1.1 `LearnerProfile`: add `jobTitle`, `hrSource`, `hrSyncedAt`. `Enrolment`: add `outOfScopeSince`. `Regulation`: add `audienceJobTitles`. Bump the register version. Verify: `npm run check:register`.

## 2. Ownership of HR fields

- [ ] 2.1 Add an updating listener that refuses manual changes to the four HR fields on a profile with `hrSource`, and lets the integriq service account through. Verify: PHPUnit on the real event: hr refused, feed allowed, hand-made profile allowed.

## 3. Re-assignment

- [ ] 3.1 `RegulationAudienceResolver::covers()`: read `audienceJobTitles`. Verify: PHPUnit.
- [ ] 3.2 `RegulationAssignmentService::assignPerson(array $profile)`: enrol in scope, skip existing, return created ids. Verify: PHPUnit with a replay.
- [ ] 3.3 `lib/Listener/ProfileScopeChangeHandler.php` on the profile updated event: compare old and new department, roles, job title; call `assignPerson`; set `outOfScopeSince` on enrolments that fell out of scope. Register it. Verify: a wiring test on the real `ObjectUpdatedEvent`, including a create.

## 4. Screen

- [ ] 4.1 Compliance page: "Niet meer in scope" list; profile page: HR fields read only with the source named. Verify: `npm run check:manifest`, `npm run check:l10n`.

## 5. Cross app

- [ ] 5.1 Open an integriq issue for AFAS and humaniq source templates mapping to learniq's `LearnerProfile` (`department`, `roles`, `managerId`, `jobTitle`, `hrSource`, `hrSyncedAt`), referencing this change. Record the issue here.

## 6. Close out

- [ ] 6.1 Live check: write a profile as the integriq service account into a department in scope; see the enrolment appear.
- [ ] 6.2 Set row `comp-assign-from-hr-roles` to built once the integriq source exists, and archive this change.
