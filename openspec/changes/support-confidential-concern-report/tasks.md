# Tasks: let a learner report a concern confidentially to the counsellor

## 1. Register

- [x] 1.1 Add `ConcernReport` with the authorization of design D2, the fields, the notification of D4, and bump `info.version`. Verify: a PHPUnit register test asserting the read, create, update and delete entries, no `$ref` in or out, and the notification recipients; the payload a learner posts validated against the real schema fragment; `npm run check:register`.

## 2. Reporter stamp

- [x] 2.1 Add `ConcernReportReporterStamp` (create stamps `reporterId` and `tenant_id`, update restores `reporterId`, no session refuses) and register it. Verify: red-then-green PHPUnit with real `ObjectCreatingEvent` and `ObjectUpdatingEvent`; a registrar test that the pairs are registered.

## 3. Screens

- [x] 3.1 Add the learner "Report a concern" menu, index and form, and the counsellor "Concern reports" menu, index and detail to `src/manifest.d/confidential-counsel.json`. Verify: `npm run check:specs`, `npm run check:menu-role-gates`.
- [x] 3.2 Strings in every shipped locale. Verify: `npm run check:l10n`, `npm run check:schema-l10n`, `npm run check:l10n-js`.

## 4. Close out

- [ ] 4.1 Live check: a learner files a report, a counsellor sees it and gets the notification, a teacher lists concern reports and gets none.
- [ ] 4.2 Set row `sup-report-a-concern-confidentially` to built and archive the change. Verify: parity_verify --strict.
