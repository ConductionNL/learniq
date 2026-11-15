# Design: send the published report card home as a PDF

## Context

At development `de2d7388`:

- `ReportCard` transitions: `publishToParents` (finalised to published-to-parents, `ReportCardVisibilityGuard`), `renderToPdf` and `rerenderToPdf` (self-loops, `requires: ReportCardPdfDelegationService`, which always allows).
- `lib/Listener/ReportCardPdfTransitionListener.php` renders after the save for `RENDER_ACTIONS = ['renderToPdf', 'rerenderToPdf']`, because a self-loop's guard runs before the save.
- `ReportCardPdfDelegationService::render()` posts `templateSlug` and the card data to filinq `api/v1/documents/render` (resolved through `FleetAppId::path`) with the `learniq.docudesk_api_token` bearer; on success stores `docudeskRenderStatus: rendered` and `docudeskDocumentRef` (a Files file id in filinq's storage), on failure `failed` and `docudeskRenderError`.
- filinq `ReportRenderService` stores the rendered PDF/A through `DocumentStorageService` and answers `{documentRef}`.
- `parentReportCards` (`PortalContributionProvider.php:1030`): scope on the guardian's children through the reverse join, `filter: {lifecycle: published-to-parents}`, `minTrust: substantial`, no `filesDownload`.
- portaliq serves an object's OpenRegister files to the portal user when the collection declares `filesDownload: true` (portaliq `supplier-portal` spec, "Download is opt-in per collection, fail-closed"), through the same scope as the read.

## Screen

The canvas draws the meeting (`LqRapportvergadering`: "vaststellen en publiceren voor ouders") and not what a parent sees; no board draws the download. On the staff side the files widget sits under the card data, the house pattern on other detail pages (`cred-files` on CredentialDetail). Labels: "Download as PDF" / "Download als pdf", "Render the PDF again" / "Pdf opnieuw maken", status values "rendered" / "gemaakt", "failed" / "mislukt".

## Decisions

### D1: Render on publish, after the save

Publishing is the moment the card goes home, so that is when the PDF must exist. The listener renders after `publishToParents` is saved, so a filinq outage never blocks the publish; the card is published without a PDF and the staff page shows `failed` with the retry action. `renderToPdf` on a finalised card stays for a preview before the meeting.

### D2: The PDF lives on the report card

The guardian may read the report card, never filinq's storage. Copying the rendered file onto the card as an OpenRegister file puts it under the card's own access rules and the portal's existing download path, with no new route. `docudeskDocumentRef` keeps pointing at filinq's original for traceability.

### D3: One PDF per card

A re-render replaces the attached file instead of adding a second one, so a guardian never has to guess which PDF is current. The audit trail keeps the history.

### D4: No mail with an attachment

"Send home" means the guardian can fetch it in the portal and gets the publication notification that already fans out. A PDF in an e-mail leaves the school's control; that is a separate choice for a school, not part of this row.
