---
kind: code
---

# Print the certificate as a PDF on your own template

## Why

A certificate today is a signed Open Badges record with a public verification page. An inspector, an employer or a learner who wants paper prints that page. Schools and training companies ask for a certificate in their own house style. `Course.certificateTemplate` already holds a Nextcloud file path to a PDF template, and nothing in `lib/` reads it. The portal-certificates change wrote this down: "learniq has no rendered PDF of a certificate".

filinq renders documents in a school's house style. Its open change `filinq-configurable-report-templates` adds `POST /api/v1/documents/render`, addressed by a template slug with an ad-hoc JSON payload. learniq's report card already posts to that contract (`ReportCardPdfDelegationService`). This change makes a certificate use the same route.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `cred-certificate-pdf` | Print the certificate as a PDF on your own template. | `partial`: `Course.certificateTemplate` holds a path, no renderer reads it |

## What changes

- `Course` gets `certificateTemplateSlug`, the filinq template a course's certificates use. The unused `certificateTemplate` file path is retired.
- Issuing a certificate asks filinq to render it, fail soft: a failed render never blocks the issue, and the error is kept on the certificate.
- The certificate stores `pdfDocumentRef`, `pdfRenderStatus` and `pdfRenderError`.
- The certificate page and the learner's certificate wall get a "Download PDF" action once the PDF exists. Holders, hr and compliance-officers may download; nobody else.
- Reissuing all certificates from a new template (the built `credentials-bulk-reissue`) renders the PDF again.

## Capabilities

### Modified capabilities

- `certification`: ADDED requirements for the rendered PDF.

## Impact

- **Register**: `Course.certificateTemplateSlug`; `Credential.pdfDocumentRef`, `pdfRenderStatus`, `pdfRenderError`; `certificateTemplate` removed through a repair step that copies nothing (no reader exists).
- **Backend**: `lib/Service/CertificatePdfDelegationService.php`, called from `CredentialIssuanceHandler` and the reissue run; a download route that streams the filinq document for an allowed reader.
- **Frontend**: the template field on the course edit form, the download action.
- **Depends on**: filinq `filinq-configurable-report-templates` (the render endpoint). Without filinq the certificate stays as it is, with `pdfRenderStatus: unavailable`.
