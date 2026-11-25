# Tasks: parent-row-actions-only-where-they-apply

- [x] **T1**: `cancelConferenceTime` declares `rowWhen` on `lifecycle`, `booked` and `acknowledged`
  - PHPUnit `PortalRowActionConditionsTest::testTheCancelNamesTheTimesThatCanStillBeCancelled`
- [x] **T2**: every row action of every audience declares a `rowWhen` on a projected field with real lifecycle states
  - PHPUnit `PortalRowActionConditionsTest::testEveryRowActionNamesRealStates`
- [x] **T3**: live: as Fatima on "Uw gesprekstijden" the cancel shows only on a booked or acknowledged time
  - Checked live 25 Nov on NC 34 + PostgreSQL + OpenRegister main + portaliq and thematiq development, po set, DigiD through the stub broker: her acknowledged time offers "Deze tijd annuleren", a cancelled time the run adds for the same child does not. `tests/e2e/portal-design/wilgenboom.spec.ts` "Uw gesprekstijden: the cancel only on a booked or acknowledged time", 2/2 runs passed
