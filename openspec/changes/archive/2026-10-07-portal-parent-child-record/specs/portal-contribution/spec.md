## ADDED Requirements

### Requirement: A guardian opens one child and sees everything about them

The parent audience MUST declare "My children" as the record page of `parentChildren`. With one child open the page MUST show, for that child only: the attendance figures, the report cards in `published-to-parents`, the grades on those report cards, the published homework of the child's group with whether the child handed it in, the attendance list, the coming calendar items and the school news. Every collection MUST read through the reverse join on the guardian's own children. Every other listable parent collection MUST keep its own page: its create action, its table and its detail.

#### Scenario: Fatima opens Vera
- GIVEN guardian Fatima Hulstkamp with child Vera in Groep 7
- WHEN she opens "Mijn kinderen" on the Wilgenboom site
- THEN she sees Vera's name, the figure cards, Vera's report cards, homework, attendance, calendar and news
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: Another child's record is refused
- GIVEN a record link to a pupil who is not one of Fatima's children
- WHEN she follows it
- THEN nothing of that pupil opens, because the server never returns that row
- @e2e exclude the refusal is portaliq's (`tests/record-page.spec.mjs`, "another child's record does not open"); the server scope is pinned by `PortalContributionProviderTest::testParentCollectionsUseReverseScopeValueVia`

### Requirement: A guardian reads her child's attendance figures

The record page MUST show three figure cards from the child's `attendance-summary` row with the latest `schoolYear`: absent days with the days with and without permission, late arrivals with the minutes in total, and unexcused absent days, highlighted. The school year the cards read MUST show beside them.

#### Scenario: Vera's figures
- GIVEN Vera's summary for 2025-2026
- WHEN Fatima opens Vera
- THEN she reads the absent days, with and without permission, the late arrivals and the unexcused days, marked when above zero
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: A guardian reads the homework of their child's group

Every assignment MUST carry `learnerRefs`, the LearnerProfile uuids of the pupils enrolled in its group, written by the server on every create and update and never by a client. The guardian MUST read published assignments by that list through the reverse join, and the list MUST NOT be projected to the portal. Each homework row MUST show "Handed in", "Handed in late", "Marked" or "Open" from the child's own submission.

#### Scenario: Homework of Groep 7
- GIVEN a published assignment for Groep 7 that Vera handed in, and one she did not
- WHEN Fatima opens Vera
- THEN the first reads "Ingeleverd" and the second "Open"
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: A guardian sees a calendar of what is coming

The school MUST be able to keep `school-event` records (title, start, end, kind, the whole school or some groups, the school) on a "School calendar" page for coordinators, the office and the director. A report period MAY name its school. The guardian MUST see, on her child's page and on a calendar page for all her children: the school's events for the whole school or the child's group, the holidays and study days of the school's report periods, and the child's planned conversation times; the calendar page also shows the last day to book a conversation. School events and report periods MUST be read through the reverse join on the child's school.

#### Scenario: The sports day and the autumn holiday
- GIVEN a school event for the whole school and a report period of Vera's school holding the autumn holiday
- WHEN Fatima opens the calendar
- THEN she sees both, each with its date and kind
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: A trip for another group stays off Vera's page
- GIVEN a school event for Groep 4 only
- WHEN Fatima opens Vera (Groep 7)
- THEN the event is not on Vera's page
- @e2e exclude the narrowing is portaliq's (`tests/record-page.spec.mjs`, "group-bound rows"); learniq's declaration is pinned by `ParentRecordPageTest::testTheCalendarJoinsTheChildsSchool`
