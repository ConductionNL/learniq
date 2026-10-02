# Tasks: parent-row-actions-only-where-they-apply

- [ ] **T1**: `cancelConferenceTime` declares `rowWhen` on `lifecycle`, `booked` and `acknowledged`
  - PHPUnit `PortalRowActionConditionsTest::testTheCancelNamesTheTimesThatCanStillBeCancelled`
- [ ] **T2**: every row action of every audience declares a `rowWhen` on a projected field with real lifecycle states
  - PHPUnit `PortalRowActionConditionsTest::testEveryRowActionNamesRealStates`
- [ ] **T3**: live: as Fatima on "Uw gesprekstijden" the cancel shows only on a booked or acknowledged time
