# Design: show the learner the wallet offer for their certificate

## Context

At development `de2d7388`:

- `Credential` (`lib/Settings/learniq_register.json`): `walletOfferStatus`, `walletOfferedAt`, `walletClaimedAt`, `walletAttestationRef`, `walletOfferError`, `walletOfferNote`; transitions `offerToWallet` and `recordWalletClaim` (self-loops on issued), `revoke`.
- Authorization: read for `hr`, `compliance-officers` and the holder (`match: {learnerUserId: $userId}`); update for `hr` and `compliance-officers`.
- `lib/Service/WalletOfferDelegationService.php`: `extractOfferUuid()` reads the uuid from `credentialOfferUri`; the rest of the response (`offerUrl`, `credentialOfferUri`, `qrPayload`) is dropped.
- `lib/Settings/connections.json:35` names the bearer token `openconnector_api_token`; unset, every offer fails with `walletOfferError`.
- Portal: `lib/Portal/ParticipantSitePages.php` `participantCertificates` (scope `learnerId`, issued certificates only), columns with a `render: link` for `verificationUrl`.
- Neither learniq nor portaliq ships a QR renderer.

## Screen

No board draws it. The canvas draws the teacher; the certificate count on `LqLeerling` ("Diploma's en certificaten") is the staff view. The section follows the house detail-page section style. Labels: "Add to your wallet" / "Zet in je wallet", "Open in wallet app" / "Open in wallet-app", "Scan this code with your wallet app" / "Scan deze code met je wallet-app", "In your wallet since {date}" / "In je wallet sinds {date}", "Ask your school to put this certificate in your wallet." / "Vraag je school dit certificaat in je wallet te zetten."

## Decisions

### D1: Keep the URI, not the QR payload

The QR code encodes the same `openid-credential-offer://` link. Storing the link alone keeps one value to clear on revoke and claim. The section draws the QR client side from it.

### D2: Staff start the offer

`offerToWallet` needs update rights on the credential, which the holder does not have. A learner route that runs the transition with system rights would be a write bypass on a public-facing path; the rule for this fleet is a service account or nothing. So the learner sees the offer and asks the school for one. A self-service request is a follow-up if schools ask for it.

### D3: Only the holder sees the section

The section renders when the signed-in user is the credential's `learnerUserId`. Staff keep the status fields in the data widget; they do not need the QR code, and showing it on a staff screen invites scanning it into the wrong wallet.

### D4: The link expires with the offer

When `walletOfferStatus` moves to `claimed`, `revoked` or `failed`, `walletOfferUri` is cleared in the same save. A stale link is never shown.

## Cross-repo

- portaliq: a block or column render that draws a QR code from a link field. Until it lands, the portal shows the link only, which works on the phone the wallet app is on.
