---
kind: code
depends_on: [credentials-europass-edci-export]
---

# Proposal: credentials-bulk-reissue

## Summary

When a course's certificate changes (a new certificate template, a corrected course name, a new issuer name, or the Europass form being added), an HR officer or compliance officer reissues every certificate already handed out for that course in one action. Each certificate keeps its id, its original issue date and its expiry; its signed content is rebuilt from the current course and template and signed again; the learner is told; and the certificate's history says when, by whom and why it was reissued.

## Why

Matrix: learniq `openspec/parity/capabilities.json`, row `cred-reissue-all-from-new-template` ("Reissue every certificate already handed out after the template changes, in one action."), rated `no`, `built.state: none`, built evidence `lib/Listener/CredentialIssuanceHandler.php:121`. Decision: build, two competitors rate yes; credentials is also learniq's core area.

- Demand row (changelog, counted as competitor evidence): https://docs.moodle.org/501/en/Moodle_Workplace_release_notes, Workplace 5.1 "Bulk Certificate Regeneration for Compliance: Administrators can now regenerate all issued certificates" based on templates.
- moodle-workplace, yes: https://docs.moodle.org/501/en/Moodle_Workplace_release_notes "regenerate all issued certificates based on a template"; https://docs.moodle.org/502/en/Certificates_Configuration "Regenerating a certificate is useful when the certificate template has changed".
- chamilo, yes: "source read at chamilo/chamilo-lms v3.0.1: public/main/gradebook/gradebook_display_certificate.php:197-210 (generate_all_certificates for every learner in the course) + public/main/gradebook/lib/be/category.class.php:2137 generateUserCertificate with skipGenerationIfExists false, which rebuilds existing certificates".

For the compliance segment this is the audit case: a regulator changes the wording a certificate must carry, and every valid certificate must show it.

## What learniq has today

Read at learniq `development` a84b6273.

- `lib/Listener/CredentialIssuanceHandler.php:81-148` issues one `Credential` per completed enrolment on a course with `certificateTemplate` set, once (the idempotency guard at :121-126).
- `lib/Settings/learniq_register.json` `Course.certificateTemplate`: "nc:files path to PDF certificate template". No code renders a PDF from it; it only switches issuance on.
- `lib/Service/CredentialSigningService.php:179` `buildOb3Payload()` builds the payload from the credential and course, and `signPayload()` (:247) signs it.
- The open change `credential-renewal-listener` renews an expiring credential by enrolling the learner again; it does not rebuild an issued credential.
- Nothing rebuilds or re-signs issued credentials.

## What this change builds

1. A "Reissue certificates" action on the course page (`CourseDetail`) for `hr` and `compliance-officers`, with a required reason and a preview: how many issued certificates the course has and how many are revoked or expired (those are left alone).
2. A queued job that, for every `issued` credential of the course, rebuilds the OB3 payload and, where present, the Europass form from the current course, template and issuer, signs both again with the current key, and keeps `id`, `issuedAt`, `expiresAt` and `learnerId`.
3. Reissue history on `Credential`: `reissuedAt`, `reissueCount`, `reissueReason`, `reissuedBy`, and an audit trail entry per credential.
4. A notification to each learner whose certificate was reissued, with a link to it.
5. A credential that was offered to the EUDI wallet gets `walletOfferStatus` set back to null (never offered) with a note, so staff can offer the new version; the old wallet copy stays verifiable until revoked.

## Out of scope

- Rendering a certificate PDF from `certificateTemplate`; learniq renders none today. If the certificate document is rendered later (filinq's document templating), the job calls that render too.
- Reissuing a single certificate by hand (a later small change).
- Changing who a certificate was issued to or when.

## Affected projects

- [x] `learniq`: `lib/Settings/learniq_register.json` (Credential 0.3.0), `lib/BackgroundJob/CredentialReissueJob.php`, a service and route, `src/manifest.d/learning.json`, notifications, l10n.

## Risks

- A mistaken reissue changes every certificate. Mitigation: the preview with counts, the required reason, the history on each credential, and the rule that identity and dates never change.
- Large courses. Mitigation: a queued job in batches of 200, idempotent per (credential, reissue run).
