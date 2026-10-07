# Tasks: show the learner the wallet offer for their certificate

## 1. Register and services

- [ ] 1.1 `lib/Settings/learniq_register.json` `credential`: add `walletOfferUri` (string, nullable, format uri, title "Wallet offer link"); bump the register version. Verify: validate a real credential payload against the fragment (Opis).
- [ ] 1.2 `WalletOfferDelegationService::offer()`: store `credentialOfferUri` in `walletOfferUri` on success, clear it on failure. Verify: PHPUnit with a recorded integriq response.
- [ ] 1.3 `WalletClaimSyncService` and `WalletRevocationPropagationService`: clear `walletOfferUri`. Verify: PHPUnit, constructing the real transition event.
- [ ] 1.4 The setup check names `learniq.openconnector_api_token` when it is empty. Verify: PHPUnit on the check.

## 2. Learner section

- [ ] 2.1 `src/components/sections/CredentialWalletOffer.vue`: renders only when `getCurrentUser().uid === object.learnerUserId`; QR from `walletOfferUri` with a small QR library (add to package.json, licence checked), the link, the claimed date, the empty sentence. Register in `src/registry.js` as `kind: 'section'`.
- [ ] 2.2 `src/manifest.d/people.json` CredentialDetail: `bodyWidgets` entry `credential-wallet-offer`. Verify: `npm run check:manifest`.
- [ ] 2.3 Strings in English and Dutch (design). Verify: `npm run check:l10n`.

## 3. Portal

- [ ] 3.1 `lib/Portal/ParticipantSitePages.php` `participantCertificates`: add `walletOfferUri` to `fields` and a column `{field: walletOfferUri, label: "Add to wallet", render: link}`. Verify: PHPUnit on the provider output.

## 4. Tests and close out

- [ ] 4.1 Vitest on the section: holder with offer, holder without, staff user.
- [ ] 4.2 Playwright `tests/e2e/credential-wallet-offer.spec.ts`: as staff run "offer to wallet" against the integriq test double, sign in as the learner, see the QR and link. Tag the scenarios with `@e2e`.
- [ ] 4.3 Set row `cred-push-to-eudi-wallet` to `built` once 4.2 passes live with integriq; archive this change. The portal QR stays with portaliq.
