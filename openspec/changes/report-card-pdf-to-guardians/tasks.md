# Tasks: send the published report card home as a PDF

## 1. Backend

- [ ] 1.1 `lib/Listener/ReportCardPdfTransitionListener.php`: also handle `publishToParents` when `docudeskRenderStatus !== 'rendered'`, after the save. Verify: PHPUnit with the real transition event class.
- [ ] 1.2 Attach the PDF: after a successful render read the file by `docudeskDocumentRef` (filinq storage, through the Files API as the service account) and save it on the report card through OpenRegister's file service, replacing an earlier attachment from this flow. Verify: PHPUnit; no `_rbac: false` write on a public path.
- [ ] 1.3 Remove "proposed, not-yet-verified" from the docblocks of `ReportCardPdfDelegationService` and from the `report-card` main spec requirement "docudesk PDF rendering is fail-soft ..." when this change is archived.

## 2. Portal and page

- [ ] 2.1 `lib/Portal/PortalContributionProvider.php` `parentReportCards`: `'filesDownload' => true`. Verify: PHPUnit on the provider output.
- [ ] 2.2 `src/manifest.d/learning.json` ReportCardDetail: a files widget (`integration` `files`, as `cred-files`), `docudeskRenderStatus` in the data widget with value labels, and `lifecycleActions` showing `renderToPdf` / `rerenderToPdf` labelled "Render the PDF again". Verify: `npm run check:manifest`.
- [ ] 2.3 English and Dutch strings (design). Verify: `npm run check:l10n`.

## 3. Tests and close out

- [ ] 3.1 Playwright `tests/e2e/report-card-pdf.spec.ts` with filinq installed: publish a seeded finalised card, see the PDF on the staff page; sign in as the seeded guardian (nextcloud mode in the test portal) and download it; request another child's file and get 404. Tag the scenarios with `@e2e`.
- [ ] 3.2 Set row `grd-report-card-as-pdf` to `built`, `learniq: yes`, owner learniq, evidence and `reachedOn: "portal > child > report cards > Download as PDF"`; archive this change.
