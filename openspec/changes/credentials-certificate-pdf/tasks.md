# Tasks: print the certificate as a PDF on your own template

## 1. Register

- [ ] 1.1 (partial: `certificateTemplateSlug` and the three `Credential` fields added, register 0.49.0; `certificateTemplate` is NOT removed yet, because `CredentialIssuanceHandler` issues a certificate only when it is set, so removing it stops every issue; that gate needs its own decision) `Course`: add `certificateTemplateSlug` (string, nullable); remove `certificateTemplate`. `Credential`: add `pdfDocumentRef`, `pdfRenderStatus` (enum `pending`, `rendered`, `failed`, `unavailable`), `pdfRenderError`. Bump the register version. Verify: `npm run check:register`.

## 2. Render

- [x] 2.1 Add `lib/Service/CertificatePdfDelegationService.php`, modelled on `ReportCardPdfDelegationService`: same token key and path constant, payload per design D4, fail soft. Verify: PHPUnit for success, no filinq, HTTP error, malformed body.
- [x] 2.2 Call it from `lib/Listener/CredentialIssuanceHandler.php` after signing, and from the reissue run of `credentials-bulk-reissue`. Verify: a wiring test from the real issuance event; a reissue test that asserts a new `pdfDocumentRef`.
- [x] 2.3 Validate the payload the service writes onto `Credential` against the real schema fragment (Opis), so a value the schema refuses fails the test.

## 3. Download

- [x] 3.1 Add a route and controller method `credential#pdf` that checks holder, hr or compliance-officers, then streams the filinq document. Declare `#[NoAdminRequired]` and an explicit object check. Verify: PHPUnit for holder, officer and another learner (refused).

## 4. UI

- [ ] 4.1 Course edit form: "Certificaatsjabloon" field. Certificate detail and the learner's certificate wall: "PDF downloaden" when rendered. Verify: `npm run check:manifest`.
- [ ] 4.2 Strings in every shipped locale. Verify: `npm run check:l10n`.

## 5. Close out

- [ ] 5.1 Live check with filinq's render endpoint once it exists: issue one certificate, download its PDF.
- [ ] 5.2 Set row `cred-certificate-pdf` to built and archive this change.
