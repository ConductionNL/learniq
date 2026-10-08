# Tasks: school-portals-match-their-boards

- [x] **T1**: no footer menu "Contact" in the four declarations (defect 7)
- [x] **T2**: `headingVisible`, `variant: plain` and popular links on the mbo and training heroes (defect 6)
- [x] **T3**: notice banner (po, vo, mbo), `variant: plain` on every hero, boxed table under its own heading, accent side list (FIX-P requests 08 Oct); page titles stay a level-1 `nlHeading` (portaliq #1368)
- [x] **T4**: home right column (po, vo, mbo); content page side list at the top right (four sets)
- [x] **T5**: "Kies je richting" (mbo), `nlLinkColumns` (po, vo), academy course links, certificate heading, markdown links (training)
  - PHPUnit `ExamplePortalDeclarationsTest::testTheDeclarationsFollowTheBoards`, `ExamplePortalProvisionerTest`
- [x] **T6**: guardian overview: only the board's blocks, `range: month`, readable conversation tile (defect 11)
  - PHPUnit `GuardianSitePagesTest::testTheGuardianOverviewIsHomeAndSwitchesChildren`
- [x] **T6b**: the children cards declare a derived `status` (Ziek gemeld / Op school); portaliq renders it once FIX-P adds the card lookup
  - PHPUnit `GuardianSitePagesTest::testTheGuardianOverviewIsHomeAndSwitchesChildren`
- [x] **T6c**: `residentMenu.leaveOut: [cases, tasks, access]` in the four declarations (portaliq #1394); the chip labels and the other board words arrive in Dutch through `PortalLabelTranslator`
  - PHPUnit `ExamplePortalDeclarationsTest::testEverySchoolPortalLeavesOutTheCaseItems`, `PortalLabelTranslatorTest::testTheBoardWordsArriveInDutch`
- [ ] **T7**: live: the four portals on the proof instance after a fresh site load
