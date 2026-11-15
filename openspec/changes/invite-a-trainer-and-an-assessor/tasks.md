# Tasks: invite-a-trainer-and-an-assessor

- [x] **T1**: `BpvPortalInvitation`: both roles, the account on the role's audience and the role's claim
  - PHPUnit `BpvPortalInvitationTest`
- [x] **T2**: `occ learniq:portal:invite-trainer` and `occ learniq:portal:invite-assessor`, registered in `info.xml`
  - PHPUnit `BpvPortalInvitationTest`; the commands are thin wrappers over it
- [x] **T3**: the vocational set seeds an active assessor, two portfolios with an entry each, and two active shares with the readable copies
  - `python3 scripts/example-sets/mbo.py --check`; PHPUnit `VocationalCollegeExampleSetTest`
- [ ] **T4**: a live check of both portals on an instance with the vocational set loaded (the coordinator's step)
