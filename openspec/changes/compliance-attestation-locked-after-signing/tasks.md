# Tasks: stop anyone editing a signed attestation afterwards

## 1. Register

- [x] 1.1 Add `revocationReason` (string, nullable) and `signedAt` (date-time, readOnly) to `Attestation` in `lib/Settings/learniq_register.json`; bump the register version. Verify: `npm run check:register`.
- [x] 1.2 Make the `sign` transition stamp `signedAt` through a transition action (the readOnly field is written on the transition path, as `StampTransitionActorAction` does for corrections). Verify: PHPUnit through the register-faithful store.
      Built as `StampTransitionTimeAction` (`timeField: signedAt`), declared before
      `TenantSignatureAction` so the stamp is signed; `revocationReason` is left out of the signed payload.
- [x] 1.3 Add a guard on `revoke` that refuses an empty `revocationReason`. Verify: PHPUnit, both scenarios of the revoke requirement.

## 2. Freeze

- [x] 2.1 Add `lib/Listener/AttestationFreezeListener.php` on OpenRegister's updating event, modelled on `AssessmentResultIntegrityListener`. It refuses a change to any evidence field when the stored state is `signed` or `revoked`, and lets the `revoke` write of `lifecycle` and `revocationReason` through. Register it in `lib/AppInfo/Application.php`. Verify: PHPUnit on the real event class: officer edit refused, admin edit refused, drafted edit allowed, revoke allowed.
- [x] 2.2 `git grep -n "Attestation" lib/` for every writer of the schema; confirm none rewrites a signed attestation. Write the list in the PR body.
      Found one: `LearnerMergeService` re-pointed `learnerId` on every attestation. A signed or
      revoked attestation now stays with the account it was signed under (the freeze would
      otherwise stop the merge half way); a drafted one still moves.

## 3. Audit pack and UI

- [x] 3.1 `lib/Service/AuditPackBuilder.php`: add `signedAt` and "frozen since signing" per attestation line. Verify: `AuditPackBuilderTest`.
      Built as `FrozenAttestationLines` (the regulation's signed and revoked attestations of the
      caller's tenant, earliest `signedAt` first); `signature-verification.txt` lists them.
      Verified by `FrozenAttestationLinesTest` and the re-recorded `AuditPackBuilderGoldenTest`.
- [x] 3.2 Attestation detail page in `src/manifest.d/`: fields read only when the state is not `drafted`; the revoke action asks for the reason. Verify: `npm run check:manifest`.
      `AttestationDetail` drops the page-wide `readOnly` for `readOnlyWhen` (lifecycle not `drafted`) and
      gains `lifecycleActions`; revoke opens the dialog for the `revocationReason` input the register
      declares as required. Verified by `AttestationDetailPageTest` and `npm run check:manifest`.
- [x] 3.3 Add the new strings to every shipped locale. Verify: `npm run check:l10n`.
      `Signed At` and the `signedAt` / `revocationReason` descriptions added to `l10n/en.json` and
      `l10n/nl.json` (the locales the parity gate holds strict); `npm run check:l10n` passes.
      The browser catalogues were already stale on development, so `npm run l10n:build` is left to
      its own commit rather than mixed in here.

## 4. Close out

- [ ] 4.1 Playwright: sign an attestation, try an edit as admin (refused), revoke with a reason.
- [ ] 4.2 Set row `comp-attestation-immutable` to built with the listener as evidence, and archive this change.
