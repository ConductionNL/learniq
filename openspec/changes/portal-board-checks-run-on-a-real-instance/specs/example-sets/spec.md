## ADDED Requirements

### Requirement: The board checks run against any instance that loaded the sets

The board checks in `tests/e2e/portal-design/` MUST run against an instance that loaded the four example sets, without edits. Admin requests MUST send their credentials without waiting for a challenge. A text check MUST pass only on a visible match. The sign-in step MUST use the Nextcloud sign-in button the portal declares. Signed-in pages MUST be opened at the route portaliq gives them (`/mijn/<app>/<page>`). With `PORTAL_DESIGN_EXTERNAL_STUB=1` a sign-in test MUST use the stub broker that already answers at the issuer instead of starting its own.

#### Scenario: The suite runs on the proof instance as checked in
- **GIVEN** an instance that loaded po, vo, mbo and training, with a long-lived DigiD stub at the issuer
- **WHEN** the operator runs `PORTAL_DESIGN_EXTERNAL_STUB=1 npx playwright test -c tests/e2e/portal-design.config.ts`
- **THEN** the admin calls are answered, the sign-in reaches the account pages, and every failure names the portal, not the test
- @e2e exclude this requirement is about the e2e suite itself; it is checked by running `tests/e2e/portal-design/`
