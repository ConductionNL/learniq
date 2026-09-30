# Tasks: let a learner report a concern confidentially to the counsellor

## 1. Register

- [x] 1.1 Add `ConcernReport` with the authorization of design D2, the fields, the notification of D4, and bump `info.version`. Verify: a PHPUnit register test asserting the read, create, update and delete entries, no `$ref` in or out, and the notification recipients; the payload a learner posts validated against the real schema fragment; `npm run check:register`.

## 2. Reporter stamp

- [x] 2.1 Add `ConcernReportReporterStamp` (create stamps `reporterId` and `tenant_id`, update restores `reporterId`, no session refuses) and register it. Verify: red-then-green PHPUnit with real `ObjectCreatingEvent` and `ObjectUpdatingEvent`; a registrar test that the pairs are registered.

## 3. Screens

- [x] 3.1 Add the learner "Report a concern" menu, index and form, and the counsellor "Concern reports" menu, index and detail to `src/manifest.d/confidential-counsel.json`. Verify: `npm run check:specs`, `npm run check:menu-role-gates`.
- [x] 3.2 Strings in every shipped locale. Verify: `npm run check:l10n`, `npm run check:schema-l10n`, `npm run check:l10n-js`.

## 4. Close out

- [x] 4.1a Live check of the read rule (30 Sep, OpenRegister 2.1.33-unstable.20260928180000): the shipped ConcernReport authorization block on a scratch register; the reporter reads the report by id and in the list, a confidential counsellor too, a teacher and another learner get 404 by id and an empty list. Unit proof: `ConcernReportReadAccessTest` evaluates the same block with OpenRegister's own ConditionMatcher.
- [ ] 4.1b Live check with this branch deployed: a learner files a report with someone else's `reporterId` and the stored row names the learner, the counsellors get the anonymous notification, a teacher sees "Report a concern" but not "Concern reports".
- [ ] 4.2 Set row `sup-report-a-concern-confidentially` to built and archive the change. Verify: parity_verify --strict.
