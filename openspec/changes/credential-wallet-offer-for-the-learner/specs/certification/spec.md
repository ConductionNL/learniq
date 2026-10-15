## ADDED Requirements

### Requirement: The credential keeps the wallet offer link while the offer is open

When `offerToWallet` succeeds, `Credential.walletOfferUri` MUST hold the link a wallet app opens: the `offerUrl` integriq returned (`openid-credential-offer://?credential_offer_uri=...`, the same value as its `qrPayload`), or that link built from `credentialOfferUri` when `offerUrl` is missing. When the offer is claimed, revoked or fails, `walletOfferUri` MUST be cleared in the same save. On a revocation it MUST be cleared even when integriq could not be told.

#### Scenario: An offer is created

- **GIVEN** an issued credential and integriq answering with `{offerUrl, credentialOfferUri, qrPayload}`
- **WHEN** a user in `hr` runs `offerToWallet`
- **THEN** `walletOfferStatus` is `offered` and `walletOfferUri` is the `offerUrl`
- @e2e exclude service outcome; covered by tests/Unit/Service/WalletOfferDelegationServiceTest.php (testHandledResponseRecordsOfferFields) and tests/Unit/Settings/CredentialWalletOfferUriRegisterTest.php

#### Scenario: The wallet claims it

- **GIVEN** a credential with an open offer
- **WHEN** `recordWalletClaim` records the claim
- **THEN** `walletOfferUri` is empty and `walletClaimedAt` is set
- @e2e exclude service outcome; covered by tests/Unit/Service/WalletClaimSyncServiceTest.php

#### Scenario: The credential is revoked

- **GIVEN** a credential with an open offer
- **WHEN** it is revoked
- **THEN** `walletOfferUri` is empty
- @e2e exclude service outcome; covered by tests/Unit/Service/WalletRevocationPropagationServiceTest.php

### Requirement: The setup check names the missing wallet token

The admin overview MUST name `learniq.openconnector_api_token` while it is empty: as a warning once a wallet offer has failed on it, as a note before any offer was tried. With the token set it MUST report that certificates can be offered.

#### Scenario: An offer failed for want of the token

- **GIVEN** no `learniq.openconnector_api_token` and a wallet offer that failed on it
- **WHEN** the admin opens the overview
- **THEN** a warning names `learniq.openconnector_api_token`
- @e2e exclude admin setup check; covered by tests/Unit/SetupCheck/WalletOfferTokenCheckTest.php

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

The participant portal's "My certificates" collection MUST include `walletOfferUri` and MUST show it as a `qr` column (portaliq `link-field-qr-code`): a QR code with an "Add to wallet" link on a certificate with an open offer. A `link` column cannot carry it: portaliq follows only http(s) and site links there.

#### Scenario: A participant with an open offer

- **GIVEN** a course participant signed in to the portal and an issued certificate of theirs with an open offer
- **WHEN** they open "My certificates"
- **THEN** that certificate shows an "Add to wallet" link to `walletOfferUri` and a QR code for it
- @e2e exclude manifest shape; covered by tests/Unit/Portal/ParticipantSitePagesTest.php (testTheOverviewShowsTheNextCourseDayFirst)
