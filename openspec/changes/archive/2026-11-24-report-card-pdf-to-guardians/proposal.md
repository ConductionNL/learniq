---
kind: code
---

# Send the published report card home as a PDF

## Why

The rendering is built on both sides. On a finalised report card `renderToPdf` (and `rerenderToPdf` once published) has `ReportCardPdfDelegationService` post the card to filinq's `POST /api/v1/documents/render`; filinq's `ReportRenderService` (archived side of `filinq/filinq-configurable-report-templates`, all 13 tasks done) renders the school's template to PDF/A and stores it in Files; learniq stores the answer as `docudeskDocumentRef`. Three things stop the PDF from reaching home:

1. Nothing renders it unless a staff member runs "render to PDF" by hand before publishing.
2. The PDF sits in filinq's Files storage, known to learniq only as a reference. It is not a file on the report card, so nothing can serve it.
3. The guardian's portal collection `parentReportCards` lists the card's grades as text and does not offer a download (`filesDownload` is off).

The service's own header still calls filinq's endpoint "proposed, not yet verified"; it exists on filinq development since this summer.

### Matrix rows (`openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `grd-report-card-as-pdf` | Send the report card home as a PDF. | `partial`: a PDF can be rendered by hand; the guardian never receives it |

## What changes

- Publishing a report card to parents renders its PDF when none exists, after the publish is saved, fail-soft as today.
- After a successful render learniq attaches the PDF to the report card as an OpenRegister file named `Rapport {period} {learner}.pdf`, replacing an earlier one on a re-render.
- The guardian's `parentReportCards` collection opts into downloads, so the published card shows "Download as PDF" in the portal.
- The staff page `ReportCardDetail` shows the render status and the attached PDF in a files widget, and offers "Render the PDF again" when the status is `failed`.
- The docblocks and the main spec drop "proposed contract" now that filinq ships it.

## Capabilities

### Modified capabilities

- `report-card`: ADDED requirements for render on publish, the attached PDF and the guardian's download.

## Impact

- **Backend**: `ReportCardPdfTransitionListener` also reacts to `publishToParents`; `ReportCardPdfDelegationService` (or a small `ReportCardPdfAttacher`) copies the rendered file onto the report card through OpenRegister's file API.
- **Portal**: `lib/Portal/PortalContributionProvider.php` `parentReportCards`: `filesDownload: true`.
- **Frontend**: a files widget on `ReportCardDetail`; l10n.
- **Register**: none; `docudeskRenderStatus`, `docudeskDocumentRef` and `docudeskRenderError` exist.
- **Reach**: a guardian signs in to the portal with DigiD, which waits on the identity broker decision (D1). The download works for every portal sign-in mode that reaches the parent audience.
