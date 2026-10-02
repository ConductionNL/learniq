## ADDED Requirements

### Requirement: A guardian is offered a cancel only on a time that can still be cancelled

`cancelConferenceTime` MUST declare `rowWhen: {field: lifecycle, in: [booked, acknowledged]}`, the states in which ConferenceSlotBookingSync lets a parent cancel. Every row action learniq contributes MUST declare a `rowWhen` on a field its collection projects, with values the schema's lifecycle allows. The condition is presentation only: ConferenceSlotBookingSync keeps refusing a cancel the time does not allow, including one after the booking window closed, which the condition cannot express.

#### Scenario: Fatima sees the cancel on her booked time only
- GIVEN Fatima has a booked time, a completed time and a cancelled time for Vera
- WHEN she opens "Uw gesprekstijden"
- THEN only the booked time offers "Deze tijd annuleren"
- @e2e exclude the hiding is portaliq's (`tests/row-action.spec.mjs`, update-row-action-condition); learniq's declaration is pinned by `PortalRowActionConditionsTest`, and the live check is in the PR
