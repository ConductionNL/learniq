---
kind: code
depends_on: []
---

# Proposal: pok-signature-parent-role

## Summary

A praktijkovereenkomst (POK) for a student under 18 is signed by a parent or guardian too, because a minor cannot bind themselves to it alone. `PokSignature.signerRole` gains `parent`. The POK records whether a parent must sign, `PokActivationGuard` refuses to activate a minor's POK without a signature from a parent listed on the student's learner profile, and the placement flow shows the requirement and offers the role.

## Motivation

Found by the MBO example set lane (learniq #1052, finding 4; TRACKER-R2 17:15 ds-mbo): `PokSignature.signerRole` offers `student`, `school` and `praktijkopleider` only. A minor's agreement is usually co-signed by a parent, and the schema has no role for that. In the MBO set, 72 of the 151 agreements were signed by a student who was 17 or younger that day. The set had to leave the parent out, so what the app shows as a complete agreement lacks a signature the school needs.

The learning plan flow already has this: `Signature.signerRole` has `parent`, and `LearningPlanSignatureGuard` counts a parent co-sign only from a user in the learner's `LearnerProfile.parentIds` (#180). The POK flow reuses both rules.

## Affected Projects

- [ ] Project: `learniq` — `PokSignature` and `Praktijkovereenkomst` schemas, `PokActivationGuard`, a new transition action and rule service, the signing page, the MBO example set.

## Scope

### In Scope

- `PokSignature.signerRole` gains `parent` (schema 0.2.0).
- `Praktijkovereenkomst.parentSignatureRequired` (boolean), stamped by a transition action on `requestSignatures` and `activate`; `parentSignatureCount` aggregate and an `isFullySigned` that requires the parent when the flag is set (schema 0.2.0).
- `PokActivationGuard` requires a verified parent signature when the student was under 18 on the day they signed, or has no date of birth recorded. It derives this itself and never trusts the stored flag.
- The signing page offers the parent role for a POK and says when a parent signs; the POK detail page shows the flag through its data widget.
- MBO example set: parent accounts for its minors, and a parent signature on each agreement a minor signed.

### Out of Scope

- A parent signing through the portal. Parents with a Nextcloud account sign in the app, as they do for learning plans; a portal action is a portaliq change.
- Access rules on `PokSignature` (no authorization block today, like `Signature`); access work belongs to r3-access (D23).
- Checking parental authority (`hasParentalAuthority`). `parentIds` is the list of linked parent or guardian accounts, as for learning plans.

## Approach

One rule service answers "does a parent sign this POK, and who may": it reads the placement, the learner's profile, and the student's signature date, all without RBAC (the signer may not read the profile). The guard and the stamp action both call it, so the frontend flag and the enforcement use one rule.

## New Dependencies

None.

## Impact

- `lib/Settings/learniq_register.json`: `PokSignature`, `Praktijkovereenkomst`, `info.version`.
- `lib/Lifecycle/PokActivationGuard.php`, new `lib/Service/PokParentSignatureRule.php`, new `lib/Lifecycle/Action/PokParentSignatureStampAction.php`.
- `src/utils/customPages.js`, `src/views/SignatureView.vue`, `l10n/`.
- `scripts/example-sets/mbo.py` and `lib/Settings/profiles/mbo.json`.

## Cross-Project Dependencies

None. `grading-defects-from-example-sets` (#1127) also regenerates `mbo.json` and bumps `info.version`; on a conflict, rerun the generator after merging both generator edits.

## Risks

### Risk 1: A POK without a recorded birth date now needs a parent

**Severity:** Medium. **Mitigation:** An unknown age fails closed, because the signature is a legal safeguard for minors. The refusal says to record the date of birth when the student is 18 or older. MBO schools record it for BRON, so this should be rare.

### Risk 2: A minor without a linked parent account cannot be activated

**Severity:** Medium. **Mitigation:** The refusal says a parent listed on the learner profile must sign, so the coordinator knows to link the account. That is the same rule learning plans already follow.

## Rollback Strategy

Revert the merge commit and re-import the register. POKs that carry `parentSignatureRequired` keep an unused field; `PokSignature` rows with `signerRole: parent` would fail the old enum on a save, but the schema is append-only, so they are never saved again.
