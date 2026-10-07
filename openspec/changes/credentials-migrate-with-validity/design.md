# Design: bring existing certificates and their validity dates over from the old system

## Context

At development `24b9ae95`:

- `Credential`: `learnerId`, `learnerUserId`, `courseId`, `kind` (`certificate`, `badge`, `microcredential`), `issuedAt`, `expiresAt`, `signature`, `openbadges3Payload` (required), `source` (`auto`, `manual`, `migrated`), `regulationSlug`, calculations `daysUntilExpiry`, `expiryStatus`, `isExpiringIn90Days`, `isExpiringIn30Days`.
- `lib/Listener/CredentialIssuanceHandler.php` and `lib/Service/CredentialSigningService.php` sign every new credential.
- `lib/Listener/CredentialRenewalListener` (archived `credential-renewal-listener`) signs a learner up for the refresher on expiry.
- `openspec/specs/external-training-upload/spec.md` and `POST /api/external-training/import`: CSV parsed in the browser, per-row validation server side, dry run then commit.

## Screen

No board. The canvas (`5NkFW28vZUUij43xzxHg5a`) draws the teacher; certificate management by hr and the compliance officer is listed under "Administratie en beheer: certification-beheer" in `capabilities-learniq.md` section 4, not drawn. The upload copies the external-training upload's layout: file, preview table with a status per row, confirm.

## Decisions

### D1: Same shape as the external-training upload

One pattern for two uploads. Officers learn it once, and the parsing code is shared.

### D2: Not signed by learniq

A signature says "learniq issued this". For a migrated certificate that is not true. The record carries the old number and issuer instead, and the verification page says where it came from. A later reissue through learniq signs it as learniq's own, which is a deliberate act.

### D3: Idempotent on learner, course and old number

A migration of 4,000 rows is run more than once: a test run, fixes, the real run. Matching on these three keys means a second run only adds what is new.

### D4: Validity drives everything else

`expiresAt` as given feeds the existing calculations, so coverage, warnings and refreshers start right on day one without new code.

## Risks

- Rows without an end date: imported with `expiresAt` null, which the coverage already treats as valid without expiry. The preview counts them, so the officer sees it before committing.
