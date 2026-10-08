# Design: stop anyone editing a signed attestation afterwards

## Context

At development `24b9ae95`:

- `lib/Settings/learniq_register.json` `Attestation`: lifecycle `drafted` → `signed` (`sign`, guarded by `AttestationSigningGuard`, action `TenantSignatureAction`) → `revoked` (`revoke`). Authorization: read, create and update for hr and compliance-officers.
- `lib/Lifecycle/AttestationSigningGuard.php` computes the HMAC over the evidence fields and stores `signature` and `signingKeyId`.
- `lib/Service/AuditPackBuilder.php` writes `signature-verification.txt` from the HMAC chain check.
- `lib/Listener/AssessmentResultIntegrityListener.php` is the precedent: it refuses a change to the frozen fields of a submitted attempt on OpenRegister's updating event.

## Screen

No board. The design canvas (artboard project `5NkFW28vZUUij43xzxHg5a`) draws the teacher's screens. Attestations belong to the compliance officer and hr, whose screens are not drawn yet (`capabilities-learniq.md` section 4, "Hr, compliance en privacy"). The UI change here is small: fields read only, and a reason field on revoke.

## Decisions

### D1: A listener, not `appendOnly`

`appendOnly` refuses every update, including the one the `sign` transition makes. That is why learniq#977 removed it. A listener can tell the transition's own write apart from a plain update: it lets through a write whose only changes are the ones the transition itself makes.

### D2: Frozen fields

Frozen after `signed`: `learnerId`, `lessonId`, `courseId`, `regulationSlug`, `actorIp`, `employeeId`, `score`, `xapiStatementId`, `signature`, `signingKeyId`, `signedAt`, `tenant_id`. The `revoke` transition may write `lifecycle` and `revocationReason` only. After `revoked`, every field is frozen.

### D3: No role override

The freeze applies to admins too. An attestation that is wrong is revoked with a reason, and a new one is drafted and signed. That keeps the evidence trail whole.

## Risks

- A repair step or import that rewrites attestations would now be refused. None exists today; the tasks include a grep for writers of `Attestation` so a hidden one surfaces before merge.
