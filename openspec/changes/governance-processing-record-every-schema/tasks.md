# Tasks: keep the GDPR record of processing up to date by itself

## 1. Check first

- [x] 1.1 Add `tests/validate-processing-coverage.js`: the personal-data field list (`learnerId`, `learnerRef`, `learnerUserId`, `learnerIds`, `learnerRefs`, `ncUserId`, `teacherIds`, `managerId`, `employeeId`, `parentIds`, `guardianRefs`, `givenName`, `familyName`, `birthDate`, `markedBy`, `submittedBy`, `email`, `phone`) and a failure per schema without a block. Wire it into `npm run check:register`. Verify: it fails on development with the 57 schemas listed (red before the fix).

## 2. Catalogue

- [x] 2.1 Add the new draft activities of design D1 to the processing catalogue seed, each with purpose, legal basis, data categories and review interval. Verify: `npm run check:register`.

## 3. Declarations

- [x] 3.1 Add `x-openregister-processing` to every schema the check lists, per the grouping in design D1. Write any schema whose purpose differs from its group, and why, into this file. Verify: the check passes.
- [x] 3.2 Set `logReads: true` on care and support and on results schemas (design D2). Verify: a register test asserts it.
- [x] 3.3 Bump the register version.

## 4. Close out

- [ ] 4.1 Live check: import the register, export the record of processing in OpenRegister, count the activities and their schemas.
- [ ] 4.2 Set row `gov-record-of-processing` to built and archive this change.

## Build notes

- 1.1: `tests/validate-processing-coverage.js`, wired into `npm run check:register`. On development it fails with 57 schemas (red before the fix); after 3.1 it passes.
- 2.1: the catalogue is the set of `x-openregister-processing` blocks; each new activity is declared there with `lifecycle: draft`, its purpose, legal basis, data categories and a 12-month review interval: `learniq-teaching-and-enrolment`, `learniq-assessment-and-results`, `learniq-care-and-support`, `learniq-work-placement`, `learniq-parent-contact` and `learniq-staff-administration`. The settings page's hand-written activity list (`LearniqSettings.vue`) is not extended here: that is UI with new strings, and the live record comes from OpenRegister.
- 3.1: 57 schemas declared. Schemas whose purpose differs from the design D1 grouping, and why:
  - `Staff` → new `learniq-staff-administration` (contract): it holds employees, not learners; none of the D1 purposes fits.
  - `ExcuseRequest`, `AttendanceFlag`, `AttendanceSummary` → the existing `scholiq-attendance-leerplicht`, as D1 says.
  - `LearningRecordExport`, `LearningRecordShare` → the existing `scholiq-data-exchange`: they hand a learner's record to an outside recipient, which is that activity, not teaching.
  - `RegulationExemption` → the existing `scholiq-attestations`: it exempts a person from a mandatory training rule, which is compliance training.
  - `ExamAccommodation`, `EngagementScore`, `EngagementRiskFlag`, `ObservationInstrument` → care and support: they are adjustments, risk signals and care observations about one learner.
  - `ExternalAssessor`, `BsaProgressFlag`, `BsaWarning`, `BsaDecision`, `SchoolAdvies`, `ProctoringSession`, `SelfAssessment` → assessment and results.
  - `PointAward`, `LearnerEngagement`, `EvaluationInvitation`, `Entitlement`, `Assignment`, `Portfolio`, `PortfolioEntry` → teaching and enrolment.
  `dataCategories` lists the schema's personal-data fields; `retentionReference` names the schema's declared retention, or says it is not declared.
- 3.2: `logReads: true` on care and support and on assessment and results, false on teaching and enrolment and on parent contact; asserted by `tests/Unit/Settings/ProcessingCoverageTest.php`. Work placement and staff do not log reads (D2 names neither).
- 3.3: register `info.version` 0.48.0 → 0.49.0. `OpenLearningMaterialRegisterTest` pinned the exact 0.48.0 its own change set; it now asserts at least that version, so a later bump does not break it.
- 4.1 (live import and export) and 4.2 (row and archive) are left for the live pass.
