## ADDED Requirements

### Requirement: A guardian lands on an overview of one child at a time

The parent audience MUST declare an overview page `parentOverview`, labelled "Overzicht", with `home: true`, so it opens on `/mijn`. The page MUST declare `records: { collection: parentChildren }`, one child at a time, so portaliq draws a child switcher. Below the open tasks (see the next requirement) the page MUST show, in this order: four quick actions, the coming week, the attendance figures of the latest school year, the latest absence report, the three newest grades and the two newest inbox messages, each for the chosen child (`recordField: learnerRef`). Every block MUST read a collection that goes through the reverse join on the guardian's own children. Design of record: `Main.dc.html`.

#### Scenario: The overview opens on the first child
- GIVEN a guardian with two children, Vera and Sami, at De Wilgenboom
- WHEN she signs in on the site
- THEN she lands on "Overzicht" with Vera chosen and a switch to Sami
- AND every block below the tasks is about Vera
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: Switching child changes every block
- GIVEN the guardian on "Overzicht" with Vera chosen
- WHEN she switches to Sami
- THEN the week, the figures, the latest report, the grades and the messages are Sami's
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A guardian with one child sees no switcher
- GIVEN a guardian with one child
- WHEN she opens "Overzicht"
- THEN the page shows that child and no switch
- @e2e exclude the switcher is portaliq's component (site-mijn-omgeving-components); learniq declares the same page for one or many children

### Requirement: The overview puts open tasks first

The overview MUST be a home page (`home: true`) and MUST declare a `tasks` block over `parentConferenceRounds` with `dueField: bookingClosesAt`, so each conference round in `booking-open` that invites one of her children shows as a task in portaliq's "Dit moet u nog doen". A task MUST name what to do ("Kies een tijd voor het oudergesprek") and the last day to book. A task MUST link to the booking page. With no open round, no task block MUST show, not an empty one. The task MUST NOT be narrowed per child by projecting `invitedLearnerRefs`: that list names other pupils.

#### Scenario: An open conference round is a task
- GIVEN a conference round in `booking-open` that invites Vera and closes on 9 October
- WHEN the guardian opens `/mijn`
- THEN "Dit moet u nog doen" lists a task to pick a time, with the last day 9 October
- AND the task opens the booking page
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: No open round, no task block
- GIVEN no conference round in `booking-open` for any of her children
- WHEN the guardian opens `/mijn`
- THEN no task block shows
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The overview offers four quick actions for the chosen child

The overview MUST declare four `cta` blocks, as in `Main.dc.html`: "{title} ziek of afwezig melden" on `createExcuseRequest` with `withRecord: true`; "Oudergesprek boeken" on the page `parentConferences` with `withRecord: true`; "Cijfers en rapport bekijken" on the page `parentChildren` with `withRecord: true`; "Bericht sturen aan de juf" on portaliq's conversations `route`. `createExcuseRequest` MUST declare `recordField: learnerRef`, so the tile presets the chosen child. The child cross-check of the action MUST stay as it is.

#### Scenario: Report Vera sick from the overview
- GIVEN the guardian on "Overzicht" with Vera chosen
- WHEN she taps "Vera ziek of afwezig melden"
- THEN the absence form opens with Vera already chosen
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: Grades for the chosen child
- GIVEN the guardian on "Overzicht" with Sami chosen
- WHEN she taps "Cijfers en rapport bekijken"
- THEN Sami's record page opens
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: NEW: The child switcher shows the child's group

`Enrolment` MUST carry `cohortName`, a readable copy of its cohort's name, written by the server on create and update and when the cohort is renamed, never by a client. `parentGroupMemberships` MUST project it. The overview's `records` MUST declare `subtitleLookup: { collection: parentGroupMemberships, matchField: learnerRef, valueField: cohortName }`. This is new work: the group name is two hops from the child today.

#### Scenario: Vera, Groep 6
- GIVEN Vera enrolled in the cohort "Groep 6"
- WHEN the guardian opens "Overzicht"
- THEN the switcher reads "Vera" with "Groep 6" under it
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The guardian menu is grouped per child

"Overzicht" and "Agenda" MUST declare `group: Mijn omgeving`. "Afwezigheid", "Cijfers en rapporten" and "Oudergesprekken" MUST each be a record page on `parentChildren` with `perRecord: parentChildren`, so the menu lists them once per child under that child's name. The pages `ParentPortalCollections::pages()` builds today MUST keep their ids and routes and MUST declare `menu: false`. Design of record: the side navigation of `Main.dc.html`.

#### Scenario: The menu names each child
- GIVEN a guardian with Vera and Sami
- WHEN she opens any page under "Mijn omgeving"
- THEN the menu shows a group "Vera" and a group "Sami", each with three entries
- AND no entry reads "My child's grades" or "Your conference times"
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: An old route still opens
- GIVEN a bookmark to `/mijn/learniq/parentExcuseRequests`
- WHEN the guardian follows it
- THEN the page opens as before, with no menu entry of its own
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The absence page shows the form and only the latest reports

The parent audience MUST declare a record page on `parentChildren` with `perRecord: parentChildren`, "Afwezigheid", that shows the absence form for that child, then the three latest reports of that child by `dateFrom`, then a link to all reports. Each report MUST show the kind, the first day, the reason and the status in Dutch. The form MUST keep every rule of `createExcuseRequest`: the child from the guardian's own children only, a first and last day, a reason and a kind. Design of record: `LearniqAbsence.dc.html`.

#### Scenario: Only the latest three
- GIVEN Vera has seven earlier absence reports
- WHEN the guardian opens "Afwezigheid" for Vera
- THEN she sees the form, then three reports, then "Bekijk alle meldingen"
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A sick report in under a minute
- GIVEN the guardian on "Afwezigheid" for Vera on a phone
- WHEN she picks the "Ziek" card, "Vandaag" as first and last day, and sends
- THEN the report is saved for Vera with kind `illness`
- AND the form is replaced by the confirmation "De juf of meester heeft uw melding ontvangen."
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A missing answer is named at the top
- GIVEN the guardian leaves the last day empty
- WHEN she sends the form
- THEN the form lists the missing answer at the top and keeps her other answers
- @e2e exclude the error summary is portaliq's component (site-multi-step-forms); learniq's required fields are pinned by PortalContributionProviderTest

### Requirement: NEW: A grade names its subject and its weight

`GradeEntry` MUST carry `courseName`, a readable copy of its course's name, written by the server on every create and update and never by a client. The parent grade collection MUST project `courseName`, `methodName`, `methodBlock` and `weight`. A grade line on the portal MUST read the subject, then the test, then the date and the value. A weight above one MUST show as "telt N keer mee". This is new work: no readable subject exists on a grade today.

#### Scenario: A grade reads as a subject and a test
- GIVEN a published grade 8,0 for Vera, course "Rekenen", method "toets breuken", weight 1
- WHEN the guardian opens "Overzicht" for Vera
- THEN the newest grades list reads "Rekenen, toets breuken" with 8,0 and its date
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A client cannot set the course name
- GIVEN a grade create that sends `courseName` "Gym"
- WHEN the server saves it
- THEN `courseName` holds the name of the grade's own course
- @e2e exclude server stamp; covered by a unit test of the stamp written with the build

### Requirement: NEW: School notices about a child reach the guardian's inbox

The parent audience MUST declare an inbox collection (`kind: inbox`) over `report-card-parent-notification` and over `grade-notification`, through the reverse join on the guardian's own children, showing only rows whose `visibleFrom` has passed. A message MUST name the child and what happened, in Dutch ("Het rapport van Vera staat klaar"). It MUST NOT carry a grade value. This is new work: the parent contribution declares no inbox today.

#### Scenario: A published report card is a message
- GIVEN Vera's report card moves to `published-to-parents`
- WHEN the guardian opens "Berichten"
- THEN she reads "Het rapport van Vera staat klaar"
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: Another family's notice never shows
- GIVEN a grade notification for a pupil who is not her child
- WHEN the guardian opens "Berichten"
- THEN that notice is not there
- @e2e exclude server scope; pinned by PortalContributionProviderTest on the reverse join of every parent collection

### Requirement: NEW: The example primary-school portal has a signed-out home

When `ExamplePortalProvisioner` creates the `wilgenboom` portal it MUST also write its signed-out home: a hero with "Alles over school op één plek" and the sign-in panel, three cards ("Ziek of afwezig melden", "Oudergesprek boeken", "Cijfers en rapporten") and the school news. It MUST NOT write or change pages of a portal it did not create in the same run. Design of record: `LearniqHome.dc.html`.

#### Scenario: A fresh example set gets the home
- GIVEN no `wilgenboom` portal exists
- WHEN the po example set is loaded
- THEN the portal's home shows the hero, the three cards and the news
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: An edited home is left alone
- GIVEN a `wilgenboom` portal whose home an editor changed
- WHEN the po example set is loaded again
- THEN the home keeps the editor's content
- @e2e exclude provisioner behaviour; covered by ExamplePortalProvisionerTest written with the build

### Requirement: Every guardian label on the site reads in Dutch

Every label this change adds (page, menu group, block, card, task, quick action, message, success text) MUST have a Dutch entry and MUST pass through `PortalLabelTranslator`. A Dutch site MUST show no English string from learniq.

#### Scenario: The overview reads Dutch
- GIVEN the Wilgenboom site in Dutch
- WHEN the guardian opens "Overzicht"
- THEN every heading, button and menu entry from learniq is Dutch
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)
