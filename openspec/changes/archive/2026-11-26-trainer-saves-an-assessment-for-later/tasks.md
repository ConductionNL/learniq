# Tasks: trainer-saves-an-assessment-for-later

- [x] **T1**: `werkproces-assessment-draft` schema (register 0.77.0) and `createWerkprocesAssessment.draftSchema`
  - Files: lib/Settings/learniq_register.json, lib/Portal/TrainerSitePages.php, l10n/en.json, l10n/nl.json, tests/Unit/Portal/golden/PortalManifests.json
  - Test: PHPUnit `PlacementPagesTest::testTheUnfinishedAssessmentIsKeptOnItsDraftSchema`, `PortalManifestGoldenTest`
- [x] **T2**: Live: the trainer saves an assessment halfway and finishes it later (site-workplace-trainer-portal-design T9, `tests/e2e/mbo-trainer-flows.spec.ts`; needs portaliq#2829)
  - Live 26 Nov 2026 (NC 34.0.6, PostgreSQL 16, PHP 8.4, OpenRegister main with openregister#11269 applied, portaliq development with the action-draft-label PR, mbo + po sets): `mbo-trainer-flows` a, b, c 3/3, twice. Found and fixed on the way: the notes field reads "Waarom (niet verplicht)"; the save button needs `draftLabel` (portaliq action-draft-label) for the board's "Bewaren voor later"; portaliq asked the draft under app `portal` (404) until its action block passed the contributing app (portaliq endpoint-forward-draft-schema T6); the spec walks portaliq's save and resume dialogs
  - Test: PHPUnit `PortalLabelTranslatorTest::testTheTrainersSaveForLaterButtonReadsAsTheBoard`, `PortalManifestGoldenTest`
