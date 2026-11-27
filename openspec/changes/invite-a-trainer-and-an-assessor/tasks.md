# Tasks: invite-a-trainer-and-an-assessor

- [x] **T1**: `BpvPortalInvitation`: both roles, the account on the role's audience and the role's claim
  - PHPUnit `BpvPortalInvitationTest`
- [x] **T2**: `occ learniq:portal:invite-trainer` and `occ learniq:portal:invite-assessor`, registered in `info.xml`
  - PHPUnit `BpvPortalInvitationTest`; the commands are thin wrappers over it
- [x] **T3**: the vocational set seeds an active assessor, two portfolios with an entry each, and two active shares with the readable copies
  - `python3 scripts/example-sets/mbo.py --check`; PHPUnit `VocationalCollegeExampleSetTest`
- [ ] **T4**: a live check of both portals on an instance with the vocational set loaded (the coordinator's step)
  - Live 25 Nov 2026 (NC 34.0.6 + PostgreSQL 16 + OpenRegister main + portaliq development, mbo set, esdoornveen): `invite-trainer` for Edwin Waarddijk and `invite-assessor` for Ruud Jansen each answer "Invited" and write a pending portal account on `default-organisation` with the role's audience and claim (`practicalTrainerId`, `externalAssessorId`). An unknown uuid answers `person-unknown`, a bad address `email-invalid`. Accounts with those claims, signed in through the `nextcloud` mode: Ruud reads his 2 shares and 3 exam assignments, which carry names, exam and room after #2818's readable-copy fix (also on a fresh load). Edwin reads his 2 placements but "Mijn studenten" (`poLearners`) is empty. That needs openregister#11269 and portaliq#2812. Redeeming the mailed invitation was not checked: the sandbox has no mail catcher. T4 stays open for the trainer's students.
