# Tasks: trainer-saves-an-assessment-for-later

- [x] **T1**: `werkproces-assessment-draft` schema (register 0.77.0) and `createWerkprocesAssessment.draftSchema`
  - Files: lib/Settings/learniq_register.json, lib/Portal/TrainerSitePages.php, l10n/en.json, l10n/nl.json, tests/Unit/Portal/golden/PortalManifests.json
  - Test: PHPUnit `PlacementPagesTest::testTheUnfinishedAssessmentIsKeptOnItsDraftSchema`, `PortalManifestGoldenTest`
- [ ] **T2**: Live: the trainer saves an assessment halfway and finishes it later (site-workplace-trainer-portal-design T9, `tests/e2e/mbo-trainer-flows.spec.ts`; needs portaliq#2829)
