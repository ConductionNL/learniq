# Tasks: school-portals-match-their-boards

- [x] **T1**: no footer menu "Contact" in the four declarations (defect 7)
- [x] **T2**: `headingVisible`, `variant: plain` and popular links on the mbo and training heroes (defect 6)
- [x] **T3**: no first `nlHeading` that repeats the page title
- [x] **T4**: home right column (po, vo, mbo); content page side list at the top right (four sets)
- [x] **T5**: "Kies je richting" (mbo), `nlLinkColumns` (po, vo), academy course links, certificate heading, markdown links (training)
  - PHPUnit `ExamplePortalDeclarationsTest::testTheDeclarationsFollowTheBoards`, `ExamplePortalProvisionerTest`
- [x] **T6**: guardian overview: only the board's blocks, `range: month`, readable conversation tile (defect 11)
  - PHPUnit `GuardianSitePagesTest::testTheGuardianOverviewIsHomeAndSwitchesChildren`
- [ ] **T7**: live: the four portals on the proof instance after a fresh site load
