# Design: site-guardian-portal-design

## Design of record

The mockups in the portal-design canvas (artifact `3Jy3r5e5f9v9ktCLxisNG6`, source `canvas/project/`) are the design of record. This change cites them by file name:

| Mockup | Screen |
|---|---|
| `LearniqHome.dc.html` | signed-out home, Basisschool De Wilgenboom |
| `Main.dc.html` | signed-in overview, guardian with Vera (groep 6) and Sami (groep 3) |
| `LearniqAbsence.dc.html` | absence form on a phone, with the earlier reports |

## What learniq owns and what portaliq owns

Learniq declares data, pages, menu groups, blocks and labels in the parent contribution. Portaliq renders them with its own components. The keys below are the ones portaliq's `site-mijn-omgeving-components` (REQ-SMO-020 to REQ-SMO-028) and `site-multi-step-forms` (REQ-SMF-005) specify (portaliq PR #1110, commit 87423edc). Where the contract offers nothing for a mockup element, the table says so.

| Mockup element | Learniq declares | Portaliq supplies (change) |
|---|---|---|
| Overview on `/mijn` | page `parentOverview` with `home: true` | the `/mijn` home (`site-mijn-omgeving-components` D4) |
| Child switcher "Vera, Groep 6" | `records: { collection: parentChildren, titleFields: [givenName], subtitleLookup: { collection: parentGroupMemberships, matchField: learnerRef, valueField: cohortName } }` | `RecordSwitcher` (REQ-SMO-026) |
| "Dit moet u nog doen" | `{ type: tasks, collection: parentConferenceRounds, dueField: bookingClosesAt, titleFields: [name] }` | `ActionRow` with deadline badge (REQ-SMO-021) |
| "Vera ziek of afwezig melden" | `{ type: cta, action: createExcuseRequest, withRecord: true, label: "{title} ziek of afwezig melden" }` | quick-action tile (REQ-SMO-024) |
| "Oudergesprek boeken" | `{ type: cta, page: parentConferences, withRecord: true }` | quick-action tile (REQ-SMO-024) |
| "Cijfers en rapport bekijken" | `{ type: cta, page: parentChildren, withRecord: true }` | quick-action tile (REQ-SMO-024) |
| "Bericht sturen aan de juf" | `{ type: cta, route: <portaliq conversations route> }` | quick-action tile (REQ-SMO-024); the route value is portaliq's, see below |
| "Deze week voor Vera" | `calendar` block, `range: week` | calendar block (exists, `CalendarBlock`) |
| "Afwezigheid dit schooljaar" | `kpi` block over `parentAttendanceSummary` | figure tiles (`site-mijn-omgeving-components`) |
| "Nieuwste cijfers" | `collection` block, `recordField: learnerRef`, `limit: 3`, `sort: { field: gradedAt, direction: desc }` | list with "Bekijk alle ..." link (REQ-SMO-021) |
| "Berichten van school" | `{ type: inbox, collection: parentInbox, recordField: learnerRef, limit: 2 }` | inbox rows with "Nieuw" (REQ-SMO-025) |
| Menu grouped per child | page keys `group`, `perRecord`, `menu: false` | side navigation groups (REQ-SMO-020) |
| Absence form | `fieldConfigs.<field>.widget` = `choices` or `dateChoices`, `confirmation` | `ChoiceCards`, `DateInputGroup`, error summary (REQ-SMF-005) |
| Signed-out hero, cards, news | seeded page content | hero, card grid, news widgets (`site-nlds-widget-palette`) |

## The overview page

```
id: parentOverview      label: Overzicht      group: Mijn omgeving      home: true
records: { collection: parentChildren, titleFields: [givenName],
           subtitleLookup: { collection: parentGroupMemberships, matchField: learnerRef, valueField: cohortName } }
blocks:
  { type: tasks, collection: parentConferenceRounds, dueField: bookingClosesAt, titleFields: [name] }
  { type: cta, action: createExcuseRequest, withRecord: true, label: "{title} ziek of afwezig melden" }
  { type: cta, page: parentConferences, withRecord: true, label: "Oudergesprek boeken" }
  { type: cta, page: parentChildren, withRecord: true, label: "Cijfers en rapport bekijken" }
  { type: cta, route: <portaliq conversations route>, label: "Bericht sturen aan de juf" }
  { type: calendar, range: week, sources: childSources() }
  { type: kpi, collection: parentAttendanceSummary, recordField: learnerRef, ... }
  { type: collection, collection: parentExcuseRequests, recordField: learnerRef, limit: 1, sort: { field: dateFrom, direction: desc } }
  { type: collection, collection: parentGrades, recordField: learnerRef, limit: 3, sort: { field: gradedAt, direction: desc } }
  { type: inbox, collection: parentInbox, recordField: learnerRef, limit: 2 }
```

The overview reuses the child sources and the figure cards of `ParentRecordPage`. It adds no new scope. Every block reads a collection that already goes through the reverse join on the guardian's children, or the new inbox collection, which uses the same join.

Portaliq's `/mijn` home (D4) lifts every `tasks` block of a home page into its own "Dit moet u nog doen" list at the top, and drops it from the page. That is the order the mockup wants.

`withRecord: true` on the absence `cta` presets the field the action names in its `recordField` (REQ-SMO-024). So `createExcuseRequest` gains `recordField: learnerRef`. The child cross-check stays: portaliq still refuses a child who is not hers, and `ExcuseRequestOwnerStamp` checks it again.

### Tasks stay across all children

REQ-SMO-025 lets a `tasks` block narrow to the open record with `recordField`. The conference task cannot use it. The child of a round is in `ConferenceRound.invitedLearnerRefs`, which holds every invited pupil of the group. Record narrowing is presentation only, so the field would have to reach the browser. Projecting it would show the guardian other pupils' uuids, which `Assignment.learnerRefs` already rules out for the same reason. So the task lists every open round of any of her children, and names the round. A per-child task needs a server-side narrowing or a per-child row (a booking invitation per child). Neither is in this change.

### The group name under the child (NEW)

`subtitleLookup` is one hop (REQ-SMO-026). The child's group name is two hops away: `enrolment.cohortId`, then `cohort.name`. This change stamps a readable copy, `Enrolment.cohortName`, written by the server from the cohort on create and update, never by a client. `parentGroupMemberships` projects it. A cohort rename re-stamps its active enrolments. This is the same readable-copy pattern as `ReportCard.periodName` and `GradeEntry.courseName`.

### The conversation tile

The mockup's "Bericht sturen aan de juf" opens a conversation with the teacher. Portaliq's contract lets a `cta` open a `route` inside the portal. I did not find a route in portaliq that starts a conversation with a named teacher. Portaliq's resident menu has a `messages` section (`residentMenu.js`), which lists conversations. The exact route value is portaliq's to name. Until lane pq names it, the tile is specified with that placeholder and the build must not guess it. If portaliq has no compose route, the tile opens the conversations list.

## The menu

| `group` | Pages |
|---|---|
| `Mijn omgeving` | Overzicht (`parentOverview`), Agenda (`parentCalendar`); Berichten is portaliq's own inbox entry |
| per child: `perRecord: parentChildren` (portaliq titles the group by the child) | Afwezigheid, Cijfers en rapporten, Oudergesprekken |
| `Uw account` | portaliq's own Mijn gegevens and Meldingen instellen; learniq declares nothing here |

REQ-SMO-020 drops `perRecord` unless the page is a record page on the same collection. So each per-child page declares `record: { collection: parentChildren }` and `perRecord: parentChildren`:

- "Cijfers en rapporten": the existing record page `parentChildren` gets `perRecord: parentChildren`.
- "Afwezigheid": a new record page `parentAbsence` on `parentChildren`: the form, the latest three reports of that child, the figures.
- "Oudergesprekken": a new record page `parentConferences` on `parentChildren`: the "Book a time" form, then the bookings and times of that child (`recordField: learnerRef`).

Every page that `ParentPortalCollections::pages()` builds today keeps its id and its route `/mijn/learniq/<collection>` and gets `menu: false`. A bookmark keeps working; the menu stops listing them.

## Readable subject on a grade (NEW)

`GradeEntry.courseId` is a uuid. The portal cannot join courses for a guardian: `course` is not a parent collection and should not become one. The report card solved the same problem with readable copies (`periodName`, `gradeLines`), written by the server. This change does the same: a `courseName` string on `GradeEntry`, stamped on create and update from the course, never written by a client. `methodName` and `methodBlock` already exist and give "toets breuken" or "blok 2". The portal line reads `courseName`, then `methodName`.

`weight` is added to the parent projection so a grade can say "telt 2 keer mee". It is not staff-only.

## Guardian inbox (NEW)

Today the parent contribution declares no `kind: inbox` collection. The guardian sees only portaliq's own notices, such as the conference answer of `conference-answer-notice`. Two learniq records already exist for exactly this purpose:

- `report-card-parent-notification`, written when a report card is published to parents.
- `grade-notification`, written when a grade is published.

Both carry `learnerRef` and no grade values. A `parentInbox` collection over each, through the child join, with `filter` on `visibleFrom` not in the future, puts them in the portal inbox. The inbox text is the label of `event`, with the child's given name.

## Seeded signed-out home (NEW)

`ExamplePortalProvisioner` creates the `wilgenboom` portal with a title, a tagline and a theme. It does not write pages. The mockup home needs a hero with the sign-in panel, three cards ("Ziek of afwezig melden", "Oudergesprek boeken", "Cijfers en rapporten") and the school news. The provisioner writes that home page only on `created`. On `themed`, `kept` or `unchanged` it leaves an existing portal's pages alone, so it never overwrites an editor's work.

## The absence form

The action `createExcuseRequest` already has the fields and the child cross-check. This change adds presentation keys from `site-multi-step-forms` (REQ-SMF-005) only:

- `learnerRef`: `widget: choices`, one card per child from the options provider, not a select. Preset by `withRecord` from the overview tile.
- `reasonKind`: `widget: choices` with all six kinds of `PortalValueLabels::ABSENCE_KIND` as cards, for now. The mockup shows three cards. REQ-SMF-005 offers that: `choiceOptions: [illness, medical-appointment]` plus `otherLabel: "Een andere reden"`, which shows two cards and an "other" card that reveals the remaining four kinds in a select. Nothing a guardian can send changes. This option waits on Ruben's decision; it is not declared in this change.
- `dateFrom`, `dateTo`: `widget: dateChoices` with `dateChoices: 2`, so the guardian sees today, the next school day and "Een andere dag".
- `confirmation: { title: "Uw melding is verstuurd", body: "De juf of meester heeft uw melding ontvangen." }` replaces `successMessage`.

`reason` stays required in the schema. Filling it from the chosen kind when it is empty is a learniq stamp, not a portal key.

## Open decisions for Ruben

1. Persona name. The persona file is `yasmina-hulstkamp`, to keep her apart from Fatima El-Amrani. The po example set and the e2e seed her as Fatima Hulstkamp. Rename the seed, or keep it?
2. The teacher's comment on a grade. Keep it staff-only (this change), or show it to guardians?
3. Permission slips. Write `school-trip-permission` as the next change?
4. The absence kinds. Keep six cards (declared now), or show "Ziek", "Dokter of tandarts" and "Een andere reden" through `choiceOptions` and `otherLabel` (see "The absence form")?
5. The conference task per child. Accept a task that names the round across all children, or add a per-child booking invitation?
