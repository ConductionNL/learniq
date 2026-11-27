---
kind: code
---

# Bring existing certificates and their validity dates over from the old system

## Why

ProRail requirement 84942: move the mandatory certifications of at least 4,000 staff, with start and end dates, from the previous system. Without them, every compliance figure starts at zero on day one and every refresher fires at the wrong time.

`Credential.source` already has the value `migrated`, and `issuedAt` and `expiresAt` exist. Nothing creates a migrated certificate. The only bulk path is the external-training upload (`compliance-external-training-spreadsheet-upload`), which records outside training, not certificates. A certificate learniq did not issue also needs honest handling: learniq cannot sign it as its own, and the public verification page must not pretend it did.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `cred-migrate-with-validity` | Bring existing certificates and their validity dates over from the old system. | `partial`: `Credential.source` allows `migrated`; nothing creates one |

### Demand

- Tender: ProRail requirement 84942.

## What changes

- A "Certificaten overnemen" upload on the certificates page: a CSV with one row per certificate (learner id or email, course code or regulation, issued on, valid until, old certificate number, issuing body).
- A dry run first: every row shows as ready, matched or refused with the reason. Only ready rows are written on confirm.
- Each row becomes a `Credential` with `source: migrated`, the dates as given, `legacyNumber` and `legacyIssuer`, and no learniq signature.
- Migrated certificates count for compliance coverage, and the expiry warnings and the refresher sign-up run on them like on any other certificate.
- The verification page of a migrated certificate says it was taken over from the previous system on a date, by whom, and that learniq did not issue it.
- Running the same file twice creates nothing new: a row matches an existing certificate on learner, course and old number.

## Capabilities

### Modified capabilities

- `certification`: ADDED requirements for migrating certificates.

## Impact

- **Register**: `Credential.legacyNumber`, `legacyIssuer`, `migratedAt`, `migratedBy`, `migrationRunId`. `signature` and `openbadges3Payload` are no longer required when `source` is `migrated`.
- **Backend**: `POST /api/credentials/migrate` (dry run and commit), `lib/Service/CredentialMigrationService.php`; the issuance handler and signing service skip `migrated` rows; `CredentialVerifyController` answers for them.
- **Frontend**: the upload with its preview on the certificates page.
- **Owner**: the row names openregister because OpenRegister owns generic object import. Its CSV import maps columns onto a schema, but it cannot match a learner by email, refuse a row whose course does not exist, or skip a duplicate by old number. So the matching lives in learniq; the file read follows the external-training upload, which already does the same for outside training.
