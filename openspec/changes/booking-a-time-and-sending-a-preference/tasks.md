# Tasks: booking-a-time-and-sending-a-preference

- [x] **T1**: `bookConferenceSlot` "Kies een tijd" with `requiredFields: [learnerRef, slotId]`; `createConferenceSignup` "Stuur uw voorkeur" with `requiredFields: [conferenceRoundId, learnerRef]` and "Voorkeur versturen"
  - PHPUnit `GuardianSitePagesTest`, `PortalLabelTranslatorTest`
- [x] **T2**: the child's "Oudergesprekken" page with both forms
  - PHPUnit `GuardianSitePagesTest`
- [x] **T3**: e2e: the free-time booking reads "Tijd" exactly; the preference form is "Stuur uw voorkeur"
  - `tests/e2e/po-parent-flows.spec.ts` (live, after portaliq#1139)
