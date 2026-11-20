---
kind: code
---

# Stop anyone editing a signed attestation afterwards

## Why

A signed attestation is the evidence an auditor reads. Today anyone in hr or compliance-officers can still change it after signing. `lib/Lifecycle/AttestationSigningGuard.php` puts an HMAC signature and a `signingKeyId` on the attestation, so a later edit shows up as a broken signature in the audit pack. Nothing refuses the edit itself. The schema dropped `appendOnly` in learniq#977, because `appendOnly` also refused the `sign` transition, so nothing was ever signed. Both groups keep `update` because they need it to run `revoke`.

So the evidence is tamper-evident but not tamper-proof. This change makes it tamper-proof without taking `revoke` away.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `comp-attestation-immutable` | Stop anyone editing a signed attestation afterwards. | `partial`: an edit after signing breaks the HMAC check in the audit pack, nothing refuses it |

## What changes

- A listener on OpenRegister's updating event refuses any change to a signed or revoked attestation's evidence fields, for every user, admins included.
- The `revoke` transition stays open to hr and compliance-officers. It may set `lifecycle` and the new `revocationReason`, and nothing else.
- A drafted attestation stays editable, so a mistake before signing can still be fixed.
- The audit pack states, per attestation, that its fields were frozen at signing.

## Capabilities

### Modified capabilities

- `compliance-audit`: ADDED requirements for the freeze after signing and for revoke with a reason.

## Impact

- **Register**: `Attestation` gains `revocationReason` (string, nullable) and `signedAt` (date-time, read only, stamped by the `sign` transition).
- **Backend**: new `lib/Listener/AttestationFreezeListener.php`, registered in `lib/AppInfo/Application.php`; `AuditPackBuilder` adds one line per attestation.
- **Frontend**: the attestation detail page shows its fields read only once signed, and the revoke action asks for a reason.
- **Owner**: the row names openregister as owner, because a general "frozen after state X" rule would belong there. OpenRegister has no such rule today (no open change in ConductionNL/openregister covers it), so learniq freezes its own schema, the way `AssessmentResultIntegrityListener` already freezes a submitted attempt. When OpenRegister ships a declarative freeze, this listener is replaced by that declaration.
