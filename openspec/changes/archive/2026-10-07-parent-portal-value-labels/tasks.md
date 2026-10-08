# Tasks: parent-portal-value-labels

- [x] **1.1** `PortalValueLabels` holds the English labels of the excuse request status and kind of absence, the attendance status, and the conference booking and conference time statuses
  - PHPUnit `PortalLabelTranslatorTest::testEveryValueLabelMatchesTheSchemaEnum` (keys equal the enum in `learniq_register.json`)
- [x] **1.2** the parent manifest declares them on the status columns and on the absence form's `reasonKind` field config
- [x] **1.3** PortalLabelTranslator translates every `valueLabels` label and leaves the stored values alone
  - PHPUnit `PortalLabelTranslatorTest::testStatusesAndAbsenceKindsArriveInDutch`, `::testOnlyVisibleStringsAreTranslated`, `::testEveryParentLabelHasADutchEntry` (against the real `l10n/nl.json`)
- [x] **1.4** new catalogue keys in `en` and `nl`, `.js` regenerated (`npm run l10n:build`, `check:l10n`)
