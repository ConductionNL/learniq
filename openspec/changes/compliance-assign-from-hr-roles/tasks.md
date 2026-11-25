# Tasks: assign mandatory training automatically from job and role data in the HR system

## 1. Register

- [x] 1.1 `LearnerProfile`: add `jobTitle`, `hrSource`, `hrSyncedAt`. `Enrolment`: add `outOfScopeSince`. `Regulation`: add `audienceJobTitles`. Bump the register version. Verify: `npm run check:register`.

## 2. Ownership of HR fields

- [x] 2.1 Add an updating listener that refuses manual changes to the four HR fields on a profile with `hrSource`, and lets the integriq service account through. Verify: PHPUnit on the real event: hr refused, feed allowed, hand-made profile allowed.

## 3. Re-assignment

- [x] 3.1 `RegulationAudienceResolver::covers()`: read `audienceJobTitles`. Verify: PHPUnit.
- [x] 3.2 `RegulationAssignmentService::assignPerson(array $profile)`: enrol in scope, skip existing, return created ids. Verify: PHPUnit with a replay.
- [x] 3.3 `lib/Listener/ProfileScopeChangeHandler.php` on the profile updated event: compare old and new department, roles, job title; call `assignPerson`; set `outOfScopeSince` on enrolments that fell out of scope. Register it. Verify: a wiring test on the real `ObjectUpdatedEvent`, including a create.

## 4. Screen

- [x] 4.1 Compliance page: "Niet meer in scope" list; profile page: HR fields read only with the source named. Verify: `npm run check:manifest`, `npm run check:l10n`.

## 5. Cross app

- [x] 5.1 Open an integriq issue for AFAS and humaniq source templates mapping to learniq's `LearnerProfile` (`department`, `roles`, `managerId`, `jobTitle`, `hrSource`, `hrSyncedAt`), referencing this change. Record the issue here.
  - ConductionNL/integriq#3889 (24 Nov 2026).

## 6. Close out

- [x] 6.1 Live check: write a profile as the integriq service account into a department in scope; see the enrolment appear.
  - 2026-11-25 live (NC 34.0.6 + PostgreSQL 16, OpenRegister main, learniq development): app setting `hr_feed_user` = `integriq-feed` (an hr account); a published regulation with `audienceScope: department`, `audienceDepartments: [Spooronderhoud]` and one published course carrying its `regulationSlug`. (1) `integriq-feed` creates a LearnerProfile for `hr-new-01` in Spooronderhoud with `hrSource: afas`: a `pending` enrolment in that course appears at once (`source: system`, `regulationSlug` set). (2) An hr user changing `department` or `jobTitle` on that profile gets 422 "jobTitle comes from afas and can only be changed there." (3) `integriq-feed` moves the profile to Kantoor: 200, and the open enrolment gets `outOfScopeSince: 2026-11-25`. No integriq source was involved: the write came straight from the feed account, as the source will make it.
- [ ] 6.2 Set row `comp-assign-from-hr-roles` to built once the integriq source exists, and archive this change.
