---
kind: code
---

# Show the learner the wallet offer for their certificate

## Why

Staff can push a certificate towards a European digital identity wallet. `CredentialDetail` offers `offerToWallet`; `CredentialWalletTransitionListener` calls `WalletOfferDelegationService::offer()`, which posts to integriq's `POST /api/eudi/credential-offers` and stores `walletOfferStatus`, `walletOfferedAt` and `walletAttestationRef` (the offer's uuid). integriq answers with `{offerUrl, credentialOfferUri, qrPayload}`, and learniq throws the link and the QR payload away after taking the uuid from the URI. So the offer exists and nobody can claim it: a wallet needs the link or the QR code on the learner's own device, and no learner page shows either.

The delivered change is the archived `2026-07-13-eudi-wallet-credential-push`. This change adds the missing part: keep the link, and show it to the learner.

### Matrix rows (`openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `cred-push-to-eudi-wallet` | Push a certificate into someone's European digital identity wallet. | `partial`: staff can create the offer; the learner never sees it |

## What changes

- `Credential` keeps the offer link: a new `walletOfferUri` holds integriq's `credentialOfferUri` when an offer succeeds, and is cleared when the offer is revoked or claimed.
- The learner's own certificate in learniq (`/credentials/:id`, which the learner may read through the `learnerUserId` match) shows a section "Add to your wallet" with the QR code and an "Open in wallet app" link while an offer is open, and "In your wallet since ..." once claimed.
- The course participant's portal page "My certificates" gets an "Add to wallet" link on a certificate with an open offer.
- Without an open offer the section says "Ask your school to put this certificate in your wallet." The learner does not start an offer (design D2).
- The setup check names the missing `learniq.openconnector_api_token`, so an admin sees why offers fail.

## Capabilities

### Modified capabilities

- `certification`: ADDED requirements for showing the offer to the learner; MODIFIED nothing.

## Impact

- **Register**: `Credential.walletOfferUri` (string, nullable, format uri). Version bump.
- **Backend**: `WalletOfferDelegationService::offer()` stores `credentialOfferUri`; `WalletRevocationPropagationService` and `WalletClaimSyncService` clear it.
- **Frontend**: `src/components/sections/CredentialWalletOffer.vue` (QR from the link, client side), `bodyWidgets` on `CredentialDetail`, shown only to the credential's holder.
- **Portal**: `lib/Portal/ParticipantSitePages.php` `participantCertificates` adds the field and a link column. A QR block in the portal waits on portaliq (CROSS item).
