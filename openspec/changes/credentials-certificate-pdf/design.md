# Design: print the certificate as a PDF on your own template

## Context

At development `24b9ae95`:

- `lib/Settings/learniq_register.json` `Course.certificateTemplate`: "nc:files path to PDF certificate template". No code reads it.
- `lib/Listener/CredentialIssuanceHandler.php` issues the `Credential` on completion; `lib/Service/CredentialSigningService.php` signs it.
- `lib/Service/ReportCardPdfDelegationService.php` posts to filinq's `api/v1/documents/render` with a bearer token from app config, fail soft, and records status and error on the report card.
- filinq `openspec/changes/filinq-configurable-report-templates` (open on filinq `development`) specifies that endpoint, slug resolution per tenant, and an `outputQuality: print` option.

## Screen

Board `LqCursus` ("learniq: cursus") on canvas `5NkFW28vZUUij43xzxHg5a`. It draws the course page with the header action "Certificaten opnieuw uitgeven" and "Bewerken". The template choice belongs in the course's edit form behind "Bewerken", next to the course's other settings; the board does not draw the form itself. Board `LqLeerling` shows "Diploma's en certificaten" as a count on the learner page; the certificate detail behind it, where "PDF downloaden" sits, is not drawn yet. The labels follow the board's Dutch: "Certificaatsjabloon" for the field, "PDF downloaden" for the action.

## Decisions

### D1: filinq renders, learniq does not

ADR-022: an app consumes the abstraction that exists. filinq owns templates, house style and PDF output. learniq sends data, keeps the reference and never builds a PDF itself.

### D2: A slug, not a file path

A file path breaks when someone moves the file, and filinq resolves templates by slug with a tenant override. The course stores the slug.

### D3: Fail soft, like the report card

A missing filinq, a missing token or a failed call sets `pdfRenderStatus` to `unavailable` or `failed` with the reason. The certificate is issued either way, because the signed record and the verification page are the certificate; the PDF is a copy of it.

### D4: The payload

`learnerName`, `courseName`, `issuedAt`, `expiresAt` (or null), `validUntilLabel`, `verificationUrl` and a QR target equal to `verificationUrl`, `organisationRef`. The PDF always prints the verification link, so paper can be checked against the signed record.

## Risks

- filinq's endpoint is not built yet. Until it is, every render ends `unavailable`, and the row stays partial.
