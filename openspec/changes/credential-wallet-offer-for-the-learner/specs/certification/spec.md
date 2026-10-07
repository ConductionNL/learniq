## ADDED Requirements

### Requirement: The credential keeps the wallet offer link while the offer is open

When `offerToWallet` succeeds, `Credential.walletOfferUri` MUST hold the `credentialOfferUri` integriq returned. When the offer is claimed, revoked or fails, `walletOfferUri` MUST be cleared in the same save.

#### Scenario: An offer is created

- **GIVEN** an issued credential and integriq answering with a `credentialOfferUri`
- **WHEN** a user in `hr` runs `offerToWallet`
- **THEN** `walletOfferStatus` is `offered` and `walletOfferUri` is that URI

#### Scenario: The wallet claims it

- **GIVEN** a credential with an open offer
- **WHEN** `recordWalletClaim` records the claim
- **THEN** `walletOfferUri` is empty and `walletClaimedAt` is set

#### Scenario: The credential is revoked

- **GIVEN** a credential with an open offer
- **WHEN** it is revoked
- **THEN** `walletOfferUri` is empty

### Requirement: The holder sees the offer on their own certificate

On `CredentialDetail`, a section MUST show the holder (the user whose id is `learnerUserId`) a QR code and an "Open in wallet app" link for `walletOfferUri` while the offer is open, the claim date once claimed, and a sentence to ask the school when there is no offer. The section MUST NOT render for any other user.

#### Scenario: The learner scans the offer

- **GIVEN** a learner whose certificate has an open wallet offer
- **WHEN** the learner opens the certificate
- **THEN** the page shows a QR code that encodes `walletOfferUri` and an "Open in wallet app" link to it

#### Scenario: No offer yet

- **GIVEN** a learner whose certificate has no wallet offer
- **WHEN** the learner opens it
- **THEN** the section says "Ask your school to put this certificate in your wallet."

#### Scenario: Staff do not get the QR code

- **GIVEN** a user in `hr` and a certificate with an open offer
- **WHEN** that user opens the certificate
- **THEN** the wallet section is not shown

### Requirement: The course participant finds the offer in the portal

The participant portal's "My certificates" collection MUST include `walletOfferUri` and MUST show an "Add to wallet" link on a certificate with an open offer.

#### Scenario: A participant with an open offer

- **GIVEN** a course participant signed in to the portal and an issued certificate of theirs with an open offer
- **WHEN** they open "My certificates"
- **THEN** that certificate shows an "Add to wallet" link to `walletOfferUri`
