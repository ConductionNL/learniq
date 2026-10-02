# Design: site-guardian-portal-design

## Design of record

The mockups in the portal-design canvas (artifact `3Jy3r5e5f9v9ktCLxisNG6`, source `canvas/project/`) are the design of record. This change cites them by file name:

| Mockup | Screen |
|---|---|
| `LearniqHome.dc.html` | signed-out home, Basisschool De Wilgenboom |
| `Main.dc.html` | signed-in overview, guardian with Vera (groep 6) and Sami (groep 3) |
| `LearniqAbsence.dc.html` | absence form on a phone, with the earlier reports |

## What learniq owns and what portaliq owns

Learniq declares data, pages, menu groups, blocks and labels in the parent contribution. Portaliq renders them with its own components. The keys below are the ones portaliq's `site-mijn-omgeving-components` (REQ-SMO-020, REQ-SMO-021) and `site-multi-step-forms` specify (portaliq PR #1110). Where the contract offers nothing for a mockup element, the table says so.

| Mockup element | Learniq declares | Portaliq supplies (change) |
|---|---|---|
| Overview on `/mijn` | page `parentOverview` with `home: true` | the `/mijn` home (`site-mijn-omgeving-components` D4) |
| Child switcher (V Vera, S Sami) | `records: { collection: parentChildren, titleFields: [givenName] }` | `RecordSwitcher` (`site-mijn-omgeving-components`) |
| "Dit moet u nog doen" | block `{ type: tasks, collection: parentConferenceRounds, dueField: bookingClosesAt, titleFields: [name] }` | `ActionRow` with deadline badge (`site-mijn-omgeving-components`) |
| "Snel regelen" | two `cta` blocks: `createExcuseRequest`, `bookConferenceSlot` | quick-action tiles (`site-mijn-omgeving-components`) |
| "Cijfers en rapport bekijken", "Bericht sturen aan de juf" tiles | not declared | not offered: a `cta` names an action id only |
| "Deze week voor Vera" | `calendar` block, `range: week` | calendar block (exists, `CalendarBlock`) |
| "Afwezigheid dit schooljaar" | `kpi` block over `parentAttendanceSummary` | figure tiles (`site-mijn-omgeving-components`) |
| "Nieuwste cijfers" | `collection` block, `limit: 3`, `sort: { field: gradedAt, direction: desc }` | list with "Bekijk alle ..." link (`site-mijn-omgeving-components`) |
| "Berichten van school" | block `{ type: inbox, collection: parentInbox, limit: 2 }` | inbox rows with "Nieuw" (`site-mijn-omgeving-components`) |
| Menu grouped per child | page keys `group`, `perRecord`, `menu: false` | side navigation groups (`site-mijn-omgeving-components`) |
| Absence form | `fieldConfigs.<field>.widget` = `choices` or `dateChoices`, `confirmation` | `ChoiceCards`, `DateInputGroup`, error summary (`site-multi-step-forms`) |
| Signed-out hero, cards, news | seeded page content | hero, card grid, news widgets (`site-nlds-widget-palette`) |

## The overview page

```
id: parentOverview      label: Overzicht      group: Mijn omgeving      home: true
records: { collection: parentChildren, titleFields: [givenName] }
blocks:
  { type: tasks, collection: parentConferenceRounds, dueField: bookingClosesAt, titleFields: [name] }
  { type: cta, action: createExcuseRequest, label: "Ziek of afwezig melden" }
  { type: cta, action: bookConferenceSlot, label: "Oudergesprek boeken" }
  { type: calendar, range: week, sources: childSources() }
  { type: kpi, collection: parentAttendanceSummary, ... }   (the three cards of portal-parent-child-record)
  { type: collection, collection: parentExcuseRequests, limit: 1, sort: { field: dateFrom, direction: desc } }
  { type: collection, collection: parentGrades, limit: 3, sort: { field: gradedAt, direction: desc } }
  { type: inbox, collection: parentInbox, limit: 2 }
```

The overview reuses the child sources and the figure cards of `ParentRecordPage`. It adds no new scope. Every block reads a collection that already goes through the reverse join on the guardian's children, or the new inbox collection, which uses the same join.

Portaliq's `/mijn` home (D4) lifts every `tasks` block of a home page into its own "Dit moet u nog doen" list at the top, and drops it from the page. That is the order the mockup wants.

`parentConferenceRounds` has no `recordField`, so the `tasks` block is not narrowed to the chosen child by the record. It lists every open round of any of her children. The rounds carry `invitedLearnerRefs`; whether a `tasks` block on a `records` page honours a `recordField` is not in REQ-SMO-021. Until it is, the task names the round, not the child. Raised with lane pq.

The `inbox` block takes `collection` and `limit` only (REQ-SMO-021 D6). It is not narrowed to the chosen child, so it shows the newest messages about any of her children. Each message names its child, so that reads correctly.

The switcher shows the given name. The mockup also shows the group ("Groep 6"). `records.subtitleFields` takes fields of `parentChildren`, and the group name is not on `learner-profile`. The switcher shows the given name only.

The quick-action labels name the action, not the child ("Ziek of afwezig melden", not "Vera ziek of afwezig melden"). A `cta` label is a fixed string in the contract; it has no placeholder for the chosen record.

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

The action `createExcuseRequest` already has the fields and the child cross-check. This change adds presentation keys from `site-multi-step-forms` only:

- `learnerRef`: `widget: choices`, one card per child from the options provider, not a select.
- `reasonKind`: `widget: choices`. Portaliq draws one card per option, so the guardian sees the six kinds of `PortalValueLabels::ABSENCE_KIND`. The mockup shows three ("Ziek", "Dokter of tandarts", "Een andere reden"). Folding six kinds into three cards is not offered by the contract. Narrowing the options is a learniq choice: the portal action could offer `illness`, `medical-appointment` and `other` only. That drops three kinds a guardian can choose today, so it is an open decision, not part of this change.
- `dateFrom`, `dateTo`: `widget: dateChoices` with `dateChoices: 2`, so the guardian sees today, the next school day and "Een andere dag".
- `confirmation: { title: "Uw melding is verstuurd", body: "De juf of meester heeft uw melding ontvangen." }` replaces `successMessage`.

`reason` stays required in the schema. Filling it from the chosen kind when it is empty is a learniq stamp, not a portal key.

## Open decisions for Ruben

1. Persona name. The persona file is `yasmina-hulstkamp`, to keep her apart from Fatima El-Amrani. The po example set and the e2e seed her as Fatima Hulstkamp. Rename the seed, or keep it?
2. The teacher's comment on a grade. Keep it staff-only (this change), or show it to guardians?
3. Permission slips. Write `school-trip-permission` as the next change?
4. The absence kinds. Keep six cards, or offer three on the portal (see "The absence form")?
