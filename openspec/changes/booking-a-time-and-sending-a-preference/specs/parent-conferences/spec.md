## ADDED Requirements

### Requirement: A guardian books a free time or sends a preference in two forms

The parent audience MUST offer two forms. "Kies een tijd" (`bookConferenceSlot`) MUST require the child and the time (`requiredFields: [learnerRef, slotId]`). "Stuur uw voorkeur" (`createConferenceSignup`) MUST require the round and the child (`requiredFields: [conferenceRoundId, learnerRef]`) and MUST NOT ask for a time. Neither form may mark a field it needs as "niet verplicht". The child's "Oudergesprekken" page MUST show both forms.

#### Scenario: Book a free time
- GIVEN a round with direct booking that invites Vera
- WHEN the guardian opens "Kies een tijd", picks Vera and a free time, and sends
- THEN the time is booked for Vera, and the time field read "Tijd" without "niet verplicht"
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: A booking without a time is refused before the write
- GIVEN the guardian on "Kies een tijd" with no time chosen
- WHEN she sends
- THEN the form names the missing time in its error summary and nothing is written
- @e2e exclude refused by portaliq's requiredFields guard (portaliq#1139, RequiredFieldsGuardTest); learniq's declaration is pinned by tests/Unit/Portal/GuardianSitePagesTest.php

#### Scenario: Send a preference
- GIVEN a round where the school plans the times
- WHEN the guardian opens "Stuur uw voorkeur", picks the round and Vera, and sends
- THEN the request is stored without a time, and the form showed no time field
- @e2e tests/e2e/po-parent-flows.spec.ts
