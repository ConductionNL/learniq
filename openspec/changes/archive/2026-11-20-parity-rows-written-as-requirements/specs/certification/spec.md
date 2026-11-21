## ADDED Requirements

### Requirement: A certificate shows the days left before it lapses

A `Credential` with an `expiresAt` MUST compute `daysUntilExpiry` and `expiryStatus`. `expiryStatus` MUST be `none` without an expiry date, `expired` at zero days or fewer, `expiring-soon` at thirty days or fewer, and `expiring` at ninety days or fewer.

#### Scenario: A certificate that lapses in 20 days

- **GIVEN** an issued certificate whose `expiresAt` is 20 days from today
- **WHEN** its holder opens the certificate
- **THEN** it shows 20 days left and `expiryStatus` is `expiring-soon`

#### Scenario: A certificate without an expiry date

- **GIVEN** an issued certificate with no `expiresAt`
- **WHEN** it is read
- **THEN** `daysUntilExpiry` is null and `expiryStatus` is `none`

### Requirement: Revoking a certificate requires a reason

The `revoke` transition of a `Credential` (`issued` to `revoked`) MUST require a non-empty `revocationReason` and MUST store it on the credential.

#### Scenario: An HR officer revokes with a reason

- **GIVEN** an issued certificate
- **WHEN** an HR officer revokes it with the reason "Issued for the wrong course"
- **THEN** the certificate is `revoked` and carries that reason

#### Scenario: A revoke without a reason is refused

- **GIVEN** an issued certificate
- **WHEN** an HR officer revokes it without a reason
- **THEN** the transition is refused and the certificate stays `issued`

### Requirement: A certificate is read by its holder and the certificate managers only

The `Credential` authorization MUST grant read to `hr`, to `compliance-officers` and to the signed-in user named in `learnerUserId`. Administrators read through the admin bypass. Nobody else MUST be able to read a credential.

#### Scenario: The holder reads their own certificate

- **GIVEN** a certificate whose `learnerUserId` is `f.elamrani`
- **WHEN** `f.elamrani` lists credentials
- **THEN** that certificate is returned

#### Scenario: Another learner cannot read it

- **GIVEN** the same certificate
- **WHEN** another learner lists credentials
- **THEN** that certificate is not returned
