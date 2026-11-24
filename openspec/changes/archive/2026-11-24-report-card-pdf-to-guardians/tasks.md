# Tasks: send the published report card home as a PDF

## 1. Backend

- [x] 1.1 `lib/Listener/ReportCardPdfTransitionListener.php`: also handle `publishToParents` when `docudeskRenderStatus !== 'rendered'`, after the save. Verify: PHPUnit with the real transition event class.
  - Done: `ReportCardPdfTransitionListener::rendersOn()` renders after `publishToParents` when `docudeskRenderStatus` is not `rendered`; tests build the real `ObjectTransitionedEvent` (publish renders, a failed render leaves the card published, an already rendered card is not rendered again).
- [x] 1.2 Attach the PDF: after a successful render read the file by `docudeskDocumentRef` (filinq storage, through the Files API as the service account) and save it on the report card through OpenRegister's file service, replacing an earlier attachment from this flow. Verify: PHPUnit; no `_rbac: false` write on a public path.
  - Done: `ReportCardPdfAttacher` reads the rendered PDF by its Files id from the Files storage of `filinq.report_render_storage_user`, writes it on the card with OpenRegister's `FileService::saveFile()` (an upsert by name, tag `report-card-pdf`) as `Rapport {period} {learner}.pdf`, and removes an earlier `Rapport … .pdf` under another name, so the card keeps one PDF. Fail-soft: a missing storage user, a missing file or a failing write is logged and leaves the card as it is. `ReportCardPdfTransitionListener` calls it after every save whose status is `rendered`. The write runs in the signed-in staff member's context; no RBAC bypass. `ReportCardPdfAttacherTest` (7) and two listener tests.
- [x] 1.3 Remove "proposed, not-yet-verified" from the docblocks of `ReportCardPdfDelegationService` and from the `report-card` main spec requirement "docudesk PDF rendering is fail-soft ..." when this change is archived.
  - Done: the docblocks and the failure reason name filinq's endpoint; the main spec requirement "docudesk PDF rendering is fail-soft ..." now names `POST /apps/filinq/api/v1/documents/render` and no longer calls the contract proposed or the endpoint a follow-up (its heading is kept so the `@spec` anchors pointing at it stay valid).

## 2. Portal and page

- [x] 2.1 `lib/Portal/PortalContributionProvider.php` `parentReportCards`: `'filesDownload' => true`. Verify: PHPUnit on the provider output.
  - Done: `filesDownload: true` on `parentReportCards` only; `PortalContributionProviderTest::testParentManifestShape` and the recorded portal manifest pin it.
- [x] 2.2 `src/manifest.d/learning.json` ReportCardDetail: a files widget (`integration` `files`, as `cred-files`), `docudeskRenderStatus` in the data widget with value labels, and `lifecycleActions` showing `renderToPdf` / `rerenderToPdf` labelled "Render the PDF again". Verify: `npm run check:manifest`.
  - Done: `card-files` (integration `files`) on ReportCardDetail; `docudeskRenderStatus` reads through `x-enum-labels` on the property (report-card 0.1.2) in the data widget; `renderToPdf` and `rerenderToPdf` labelled "Render the PDF again". `check:manifest` passes.
- [x] 2.3 English and Dutch strings (design). Verify: `npm run check:l10n`.
  - Done: en and nl in `l10n/*.json`, `.js` rebuilt for en and nl. `check:l10n` at its baseline.

## 3. Tests and close out

- [x] 3.1 Playwright `tests/e2e/report-card-pdf.spec.ts` with filinq installed: publish a seeded finalised card, see the PDF on the staff page; sign in as the seeded guardian (nextcloud mode in the test portal) and download it; request another child's file and get 404. Tag the scenarios with `@e2e`.
  - Written: `tests/e2e/report-card-pdf.spec.ts`, run with `tests/e2e/po-flow.config.ts` on the po-parent-flows instance with filinq enabled. The guardian signs in through the stub DigiD broker, not the `nextcloud` mode: a password sign-in is trust `low` and `parentReportCards` asks `substantial`. Written, runs in nightly (not verified live here).
- [x] 3.2 Set row `grd-report-card-as-pdf` to `built`, `learniq: yes`, owner learniq, evidence and `reachedOn: "portal > child > report cards > Download as PDF"`; archive this change.
  - Done: the row is `built`, `learniq: yes`, its evidence names the listener, the attacher, the portal collection and the page, `reachedOn` is the guardian's download, and its requirements cite `openspec/specs/report-card`; the change is archived with its four requirements added to that spec (18 to 22).
