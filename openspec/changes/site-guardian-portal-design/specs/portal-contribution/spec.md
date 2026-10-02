## ADDED Requirements

### Requirement: A guardian lands on an overview of one child at a time

The parent audience MUST declare an overview page `parentOverview`, labelled "Overzicht", as the first page of its contribution. The page MUST take its child from `parentChildren` (`records`), one child at a time, so portaliq can draw a child switcher. With a child chosen the page MUST show, in this order: open tasks, four quick actions, the coming week, the attendance figures of the latest school year, the latest absence report, the three newest grades and the two newest inbox messages, all for that child only. Every block MUST read a collection that goes through the reverse join on the guardian's own children. Design of record: `Main.dc.html`.

#### Scenario: The overview opens on the first child
- GIVEN a guardian with two children, Vera and Sami, at De Wilgenboom
- WHEN she signs in on the site
- THEN she lands on "Overzicht" with Vera chosen and a switch to Sami
- AND every block on the page is about Vera
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: Switching child changes every block
- GIVEN the guardian on "Overzicht" with Vera chosen
- WHEN she switches to Sami
- THEN the tasks, the week, the figures, the grades and the messages are Sami's
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A guardian with one child sees no switcher
- GIVEN a guardian with one child
- WHEN she opens "Overzicht"
- THEN the page shows that child and no switch
- @e2e exclude the switcher is portaliq's component (site-mijn-omgeving-components); learniq declares the same page for one or many children

### Requirement: The overview puts open tasks first

The overview MUST show, above every other block, each conference round in `booking-open` that invites the chosen child, as a task. A task MUST name what to do ("Kies een tijd voor het oudergesprek"), the teacher and the child, and the last day to book from `bookingClosesAt`. A task MUST link to the booking page of that child. A child without an open round MUST show no task block, not an empty one.

#### Scenario: An open conference round is a task
- GIVEN a conference round in `booking-open` that invites Vera and closes on 9 October
- WHEN the guardian opens "Overzicht" for Vera
- THEN the first block reads a task to pick a time, with the last day 9 October
- AND the task opens the booking page for Vera
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: No open round, no task block
- GIVEN no conference round in `booking-open` for Sami
- WHEN the guardian switches to Sami
- THEN no task block shows
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The overview offers four quick actions

The overview MUST declare four `cta` blocks, each on an action or page that exists: report the chosen child absent (`createExcuseRequest`, child preset), book a conversation (the page of `parentConferenceFreeSlots`), open grades and report cards (the record page of the child), and write to the teacher (portaliq's new conversation page). Each label MUST name the child where the mockup does ("Vera ziek of afwezig melden").

#### Scenario: Report sick from the overview
- GIVEN the guardian on "Overzicht" with Vera chosen
- WHEN she taps "Vera ziek of afwezig melden"
- THEN the absence form opens with Vera already chosen
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The guardian menu is grouped per child

Every parent page MUST declare a menu group. The top group MUST hold "Overzicht", "Berichten" and "Agenda". Then one group per child MUST hold "Afwezigheid", "Cijfers en rapporten" and "Oudergesprekken" for that child. Then "Uw account". The pages `ParentPortalCollections::pages()` builds today MUST keep their ids and routes and MUST be hidden from the menu. Design of record: the side navigation of `Main.dc.html`.

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

The parent audience MUST declare a page per child, "Afwezigheid", that shows the absence form for that child, then the three latest reports of that child by `dateFrom`, then a link to all reports. Each report MUST show the kind, the first day, the reason and the status in Dutch. The form MUST keep every rule of `createExcuseRequest`: the child from the guardian's own children only, a first and last day, a reason and a kind. Design of record: `LearniqAbsence.dc.html`.

#### Scenario: Only the latest three
- GIVEN Vera has seven earlier absence reports
- WHEN the guardian opens "Afwezigheid" for Vera
- THEN she sees the form, then three reports, then "Bekijk alle meldingen"
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A sick report in under a minute
- GIVEN the guardian on "Afwezigheid" for Vera on a phone
- WHEN she picks "Ziek", "Vandaag" as first and last day, and sends
- THEN the report is saved for Vera with kind `illness`
- AND she reads "De juf of meester heeft uw melding ontvangen."
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
