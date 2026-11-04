# Tasks: show the learner the wallet offer for their certificate

## 1. Register and services

- [x] 1.1 `lib/Settings/learniq_register.json` `credential`: add `walletOfferUri` (string, nullable, format uri, title "Wallet offer link"); bump the register version. Verify: validate a real credential payload against the fragment (Opis).
- [x] 1.2 `WalletOfferDelegationService::offer()`: store integriq's `offerUrl` (the `openid-credential-offer://` link; built from `credentialOfferUri` when missing) in `walletOfferUri` on success, clear it on failure. Verify: PHPUnit with a recorded integriq response.
- [x] 1.3 `WalletClaimSyncService` and `WalletRevocationPropagationService`: clear `walletOfferUri`. Verify: PHPUnit, constructing the real transition event.
- [x] 1.4 The setup check (`WalletOfferTokenCheck`, a warning once an offer failed on it, else a note) names `learniq.openconnector_api_token` when it is empty. Verify: PHPUnit on the check.

## 2. Learner section

- [x] 2.1 `src/components/sections/CredentialWalletOffer.vue`: renders only when `getCurrentUser().uid === object.learnerUserId`; the QR is nextcloud-vue's `CnQrCode` over `walletOfferUri` (no new library), the "Naar wallet" link, the claim date, a sentence after a failed offer and the empty sentence. Registered in `src/registry.js` as `kind: 'section'`. The learner does not start an offer: "Nieuwe code maken" and "Opnieuw proberen" on LqLlCertificaat stay with the school (design D2), so the failed and empty states ask the school. Verify: tests/unit-js/credentialWalletOffer.test.mjs.
- [x] 2.2 `src/manifest.d/people.json` CredentialDetail: `bodyWidgets` entry `credential-wallet-offer`. Verify: `npm run check:manifest`.
- [x] 2.3 Strings in English and Dutch (design). Verify: `npm run check:l10n`.

## 3. Portal

- [x] 3.1 `lib/Portal/ParticipantSitePages.php` `participantCertificates`: add `walletOfferUri` to `fields` and a column `{field: walletOfferUri, label: "Wallet", render: "qr", linkLabel: "Add to wallet"}` (portaliq link-field-qr-code T10; a `link` render drops a non-http link). `linkLabel` is translated. Verify: PHPUnit on the provider output.

## 4. Tests and close out

- [x] 4.1 Node test on the section's state (learniq has no vitest): holder with offer, claimed, failed, none, staff user (tests/unit-js/credentialWalletOffer.test.mjs).
- [ ] 4.2 Playwright `tests/e2e/credential-wallet-offer.spec.ts`: as staff run "offer to wallet" against the integriq test double, sign in as the learner, see the QR and link. Tag the scenarios with `@e2e`.
- [ ] 4.3 Set row `cred-push-to-eudi-wallet` to `built` once 4.2 passes live with integriq; archive this change. The portal QR stays with portaliq.
