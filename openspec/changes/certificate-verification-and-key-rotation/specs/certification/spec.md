# certification Specification (delta)

## ADDED Requirements

### Requirement: Anyone checks a certificate from its link without logging in

Every issued `Credential` SHALL carry a `verificationUrl` that points at `GET /api/credentials/{id}/verify`. The route SHALL need no account, and SHALL be rate limited and protected against guessing. It SHALL check the signature against the school's key and answer whether the certificate is valid, with its issue date, expiry date and issuer. A revoked certificate SHALL answer not valid, with the revocation date and reason. An expired one SHALL answer not valid with its expiry date. A failed signature SHALL answer not valid with `signature_invalid`. An unknown id SHALL answer 404 and count as a guess. The answer SHALL carry the certificate title and the holder's display name only for a valid or an expired certificate, never for an unknown id.

#### Scenario: An employer checks a valid certificate

- **GIVEN** an issued certificate with an intact signature and no expiry
- **WHEN** an employer opens its verification link, signed out
- **THEN** the answer says valid, with the issue date and the school as issuer

#### Scenario: A revoked certificate

- **GIVEN** a certificate the school revoked with the reason "issued in error"
- **WHEN** someone opens its verification link
- **THEN** the answer says not valid, with the revocation date and "issued in error"

#### Scenario: Guessing ids is slowed down

- **GIVEN** an anonymous caller
- **WHEN** the caller requests ids that do not exist, one after another
- **THEN** each answers 404 and the brute-force throttle slows the next request

#### Scenario: An unknown id reveals no name

- **GIVEN** an anonymous caller
- **WHEN** the caller requests an id that does not exist
- **THEN** the 404 answer carries no certificate title and no holder name

### Requirement: Rotating the signing key keeps earlier certificates checkable

An administrator SHALL be able to rotate the school's credential signing key. Rotation SHALL make a new key pair active and keep the previous public key for checking only, up to the 32 most recent previous keys. The private key of a previous pair SHALL NOT be kept. A certificate's proof SHALL name its key (`kid`), and checking SHALL use the key it names, active or kept. New certificates SHALL be signed with the active key only.

#### Scenario: A certificate from before the rotation

- **GIVEN** a certificate signed in 2025 with the key that was active then
- **WHEN** the administrator rotates the key and an employer checks the certificate afterwards
- **THEN** the answer says valid

#### Scenario: A certificate after the rotation

- **GIVEN** a rotated key
- **WHEN** a new certificate is issued
- **THEN** its proof names the new key

### Requirement: The verification link opens a page that says in words what was checked

Opening `verificationUrl` in a browser SHALL show a public page, as LqVerifieren draws it, without an account. The page SHALL say whether the certificate is real and still valid. For a valid or an expired certificate it SHALL show the certificate title, the holder's name, the issuer, the issue date, the expiry date or "no end date", and when it was checked. It SHALL list the four checks with one sentence each: the signature is right, it is not revoked, it is not expired, and its content has not changed. It SHALL show nothing else from the learner's record. For an unknown id it SHALL say that no certificate was found, and show no title and no name. A program asking for JSON SHALL keep getting the answer of the route.

#### Scenario: Someone opens a link they were given

- **GIVEN** a valid certificate "Certificaat rekenen 2F" for Fenna de Jong
- **WHEN** someone opens its verification link in a browser, signed out
- **THEN** the page says "Echt en geldig" with the title, the name and the four checks

#### Scenario: A revoked certificate in the browser

- **GIVEN** a revoked certificate
- **WHEN** someone opens its link in a browser
- **THEN** the page says it is no longer valid, with the revocation date, and shows no title or name

#### Scenario: An expired certificate in the browser

- **GIVEN** a certificate "Certificaat rekenen 2F" for Fenna de Jong that expired on 1 September 2026
- **WHEN** someone opens its link in a browser
- **THEN** the page says it is no longer valid, with the expiry date, the title and the name

#### Scenario: An unknown id in the browser

- **GIVEN** an id that matches no certificate
- **WHEN** someone opens a verification link with that id in a browser
- **THEN** the page says no certificate was found and shows no title and no name
