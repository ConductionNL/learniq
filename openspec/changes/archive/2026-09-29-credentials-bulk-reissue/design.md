# Design: credentials-bulk-reissue

## Context

A credential's signed content is built once, at issue time, by `CredentialSigningService` (`lib/Service/CredentialSigningService.php:93` `check()`, :179 `buildOb3Payload()`, :247 `signPayload()`). After `credentials-europass-edci-export` it also carries `edciPayload`, built by `EdciPayloadBuilder` from the course, learner and issuer. Both depend on data that can change after issue: the course name and credits, the issuer name (`Credential.issuedBy`, read from `Course.issuerName` by `CredentialIssuanceHandler.php:143`; `Course` declares no `issuerName`, so it is empty today and the organisation name has to come from app config or the `School` record) and the certificate template. This change rebuilds and re-signs issued credentials in bulk and records that it did.

## Data model

`Credential` (0.2.0 to 0.3.0):

| property | type | notes |
|---|---|---|
| `reissuedAt` | date-time, nullable | last reissue |
| `reissueCount` | integer, default 0 | |
| `reissueReason` | string, nullable | the reason given for the last reissue |
| `reissuedBy` | string, nullable | user id |
| `reissueRunId` | string, nullable | id of the run, for idempotency |

`id`, `learnerId`, `courseId`, `issuedAt`, `expiresAt` and `kind` are never written by a reissue. A new `x-openregister-lifecycle` self-loop transition `reissue` (`issued` to `issued`) with `authorization: ["hr", "compliance-officers"]` records each reissue in OpenRegister's audit trail, the way `offerToWallet` is a self-loop today.

## Flow

1. `CourseDetail` (`src/manifest.d/learning.json:539`) gets a header action "Reissue certificates", shown to `hr` and `compliance-officers` when the course has `certificateTemplate` set. It opens `src/modals/ReissueCertificatesModal.vue`: counts of `issued`, `revoked` and `expired` credentials for the course, a required reason field, confirm.
2. `POST /api/courses/{courseId}/credentials/reissue` with `{reason}` checks the caller's group in the body (gate 7), creates a run id and queues `CredentialReissueJob` with (courseId, runId, reason, caller).
3. The job reads `issued` credentials of the course in batches of 200. For each credential without this `reissueRunId` it rebuilds the payloads with the current course, template and issuer, signs them with the current tenant key, fires `reissue` with the new payloads and the history fields, and moves on. A failure on one credential is logged with the credential id and does not stop the run.
4. When a credential has `walletOfferStatus` `offered` or `claimed` (enum `offered`, `claimed`, `revoked`, default null, `lib/Settings/learniq_register.json:483`), the job sets it back to null and appends a note that the wallet holds the previous version.
5. Each learner gets one notification per run ("Your certificate for {course} has been reissued") through the register's notification dialect on the `reissue` transition.
6. A run summary (processed, skipped, failed) is stored in app config under the run id and shown in the modal when reopened.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| `reissue` transition and its audit entry | declarative, `x-openregister-lifecycle` self-loop | the same shape as `offerToWallet` |
| learner notification | declarative, `x-openregister-notifications` on `reissue` | ADR-031 notification dialect |
| rebuild and re-sign every credential of a course | imperative, `CredentialReissueJob` (queued) | ADR-031 exception: scheduled bulk work with a cryptographic signature per object |

## Seed data

On the training example set: the course "BHV basisopleiding" with twelve issued certificates, two of them with `reissueCount: 1`, `reissueReason: "Nieuwe tekst certificaat na wijziging NIBHV-eisen"`, `reissuedBy: "hr.jansen"`.

## Open points

- Whether a reissue should also be possible for a certificate template shared by several courses; this change works per course, as `certificateTemplate` is a course property.
