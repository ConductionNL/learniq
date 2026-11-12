# Tasks: employer-signs-in-with-eherkenning

- [x] **T1**: `PortalEmployerInviteController` and its route
  - PHPUnit `PortalEmployerInviteControllerTest`
- [x] **T2**: portaliq: an existing account's audience wins, the session names the company (portaliq #1211)
- [x] **T3**: e2e: invitation, eHerkenning sign-in through the stub, Linda's overview with "Jansen Installatietechniek BV" (`tests/e2e/portal-design/warmtepompacademie.spec.ts`, runs with `PORTAL_DESIGN_EHERKENNING_ISSUER`)
- [ ] **T4**: the proof instance's eHerkenning issuer configured by the spin-up lane (proposal, "The spin-up")

Archived on 12 Nov 2026 with the open task above: T4 is a deployment step on the proof instance, not code; the build task is done (lib/Controller/PortalEmployerInviteController.php).
