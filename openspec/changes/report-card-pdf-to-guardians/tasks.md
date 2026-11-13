# Tasks: send the published report card home as a PDF

## 1. Backend

- [x] 1.1 `lib/Listener/ReportCardPdfTransitionListener.php`: also handle `publishToParents` when `docudeskRenderStatus !== 'rendered'`, after the save. Verify: PHPUnit with the real transition event class.
  - Done: `ReportCardPdfTransitionListener::rendersOn()` renders after `publishToParents` when `docudeskRenderStatus` is not `rendered`; tests build the real `ObjectTransitionedEvent` (publish renders, a failed render leaves the card published, an already rendered card is not rendered again).
- [ ] 1.2 Attach the PDF: after a successful render read the file by `docudeskDocumentRef` (filinq storage, through the Files API as the service account) and save it on the report card through OpenRegister's file service, replacing an earlier attachment from this flow. Verify: PHPUnit; no `_rbac: false` write on a public path.
  - Not done in this pass: reading filinq's stored PDF (as `filinq.report_render_storage_user`) and writing it on the card needs OpenRegister's `FileService::addFile(FileTarget, FileUpload)`, which learniq's OpenRegister test stubs do not carry yet. Until it lands the portal download has no file to offer, and the files widget stays empty.
- [ ] 1.3 Remove "proposed, not-yet-verified" from the docblocks of `ReportCardPdfDelegationService` and from the `report-card` main spec requirement "docudesk PDF rendering is fail-soft ..." when this change is archived.

## 2. Portal and page

- [x] 2.1 `lib/Portal/PortalContributionProvider.php` `parentReportCards`: `'filesDownload' => true`. Verify: PHPUnit on the provider output.
  - Done: `filesDownload: true` on `parentReportCards` only; `PortalContributionProviderTest::testParentManifestShape` and the recorded portal manifest pin it.
- [x] 2.2 `src/manifest.d/learning.json` ReportCardDetail: a files widget (`integration` `files`, as `cred-files`), `docudeskRenderStatus` in the data widget with value labels, and `lifecycleActions` showing `renderToPdf` / `rerenderToPdf` labelled "Render the PDF again". Verify: `npm run check:manifest`.
  - Done: `card-files` (integration `files`) on ReportCardDetail; `docudeskRenderStatus` reads through `x-enum-labels` on the property (report-card 0.1.2) in the data widget; `renderToPdf` and `rerenderToPdf` labelled "Render the PDF again". `check:manifest` passes.
- [x] 2.3 English and Dutch strings (design). Verify: `npm run check:l10n`.
  - Done: en and nl in `l10n/*.json`, `.js` rebuilt for en and nl. `check:l10n` at its baseline.

## 3. Tests and close out

- [ ] 3.1 Playwright `tests/e2e/report-card-pdf.spec.ts` with filinq installed: publish a seeded finalised card, see the PDF on the staff page; sign in as the seeded guardian (nextcloud mode in the test portal) and download it; request another child's file and get 404. Tag the scenarios with `@e2e`.
- [ ] 3.2 Set row `grd-report-card-as-pdf` to `built`, `learniq: yes`, owner learniq, evidence and `reachedOn: "portal > child > report cards > Download as PDF"`; archive this change.
