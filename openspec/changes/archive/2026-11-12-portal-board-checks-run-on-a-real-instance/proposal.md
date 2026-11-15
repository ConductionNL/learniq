---
kind: spec
depends_on: [example-portal-declares-its-site]
---

# Proposal: portal-board-checks-run-on-a-real-instance

## Why

Proof run 1 (portal-proof, 06 Oct) ran the board checks of the four school portals (`tests/e2e/portal-design/`) as merged against a fresh instance: 13 passed, 23 failed, 2 skipped. Almost every failure was the test, not the portal:

1. The admin API calls sent no password. OpenRegister answers 403 and learniq 401 without a Basic challenge, so Playwright never sent the credentials it had.
2. A text check took the first match even when that match was hidden. The site title sits in the header twice, once hidden behind the logo.
3. The student sign-in looked for "Inloggen met uw account"; the declared buttons read "Inloggen met je schoolaccount" (vo, mbo) and "Inloggen als deelnemer" (training).
4. Signed-in pages were opened at `/mijn/<page>`; portaliq routes them at `/mijn/<app>/<page>`.
5. The DigiD test starts its own stub broker on the issuer port. Docker Desktop forwards a port that opens during the run too late for the container to reach it.

The proof lane fixed these in a copy outside the repo; this change brings them into the suite.

## What changes

- One `ADMIN_CREDENTIALS` in `boards.ts` with `send: 'always'`, used by every admin request context.
- `expectTexts` counts only visible matches.
- `signInAs` clicks the declared Nextcloud sign-in button of any of the portals.
- The esdoornveen and warmtepompacademie specs open `/mijn/learniq/<page>`.
- `startBroker()` starts the stub broker, or with `PORTAL_DESIGN_EXTERNAL_STUB=1` uses a long-lived stub that already answers at the issuer.

## Not in this change

- No portal or product code. The defects the run found in the portals themselves are fixed in their own changes.
