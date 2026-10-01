# Tasks: guardians read the grades on their child's published report cards

- [x] 1.1 `ReportCard.periodName` and `ReportCard.gradeLines` in the register (0.34.26), with l10n for their descriptions. Verify: `npm run check:schema-l10n`, `npm run check:specs`.
- [x] 1.2 `ReportCardGradeLines` derives them. Verify: `phpunit tests/Unit/Service/ReportCardGradeLinesTest.php` (red before: the class did not exist).
- [x] 1.3 `ReportCardGradeLinesStamp` writes them on every create and update, keeps stored values when names cannot be read. Verify: `phpunit tests/Unit/Listener/ReportCardGradeLinesStampTest.php`.
- [x] 1.4 `BackfillReportCardGradeLines` (post-migration) for existing cards, idempotent. Verify: `phpunit tests/Unit/Repair/BackfillReportCardGradeLinesTest.php`.
- [x] 1.5 The po and vo example sets carry them. Verify: `python3 scripts/example-sets/po.py --check`, `vo.py --check`, and `ReportCardGradeLinesTest::testTheExampleSetsCarryWhatTheServerDerives`.
- [x] 1.6 `parentReportCardGrades` in the parent contribution. Verify: `phpunit tests/Unit/Portal/PortalContributionProviderTest.php` (red before: 2 failures; green after), and the manifests of all four audiences dumped before and after: student, praktijkopleider and external-assessor identical, parent identical apart from the one added collection.
- [x] 1.7 The po parent e2e spec checks the grades and that a draft never shows (`tests/e2e/po-parent-flows.spec.ts`, test e).
- [ ] 1.8 Live on the primary school throwaway as Fatima Hulstkamp (Vera).
