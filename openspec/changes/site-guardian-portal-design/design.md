# Design: site-guardian-portal-design

## Design of record

The mockups in the portal-design canvas (artifact `3Jy3r5e5f9v9ktCLxisNG6`, source `canvas/project/`) are the design of record. This change cites them by file name:

| Mockup | Screen |
|---|---|
| `LearniqHome.dc.html` | signed-out home, Basisschool De Wilgenboom |
| `Main.dc.html` | signed-in overview, guardian with Vera (groep 6) and Sami (groep 3) |
| `LearniqAbsence.dc.html` | absence form on a phone, with the earlier reports |

## What learniq owns and what portaliq owns

Learniq declares data, pages, menu groups, blocks and labels in the parent contribution. Portaliq renders them with its own components. This change names a component only to say which portaliq change supplies it.

| Mockup element | Learniq declares | Portaliq supplies (change) |
|---|---|---|
| Child switcher (V Vera, S Sami) | overview page with `records: parentChildren` | switcher (`site-mijn-omgeving-components`) |
| "Dit moet u nog doen" | `tasks` block over `parentConferenceRounds` | task row with deadline (`site-mijn-omgeving-components`) |
| "Snel regelen" | four `cta` blocks | quick-action tiles (`site-mijn-omgeving-components`) |
| "Deze week voor Vera" | `calendar` block, `range: week` | calendar block (exists, `CalendarBlock`) |
| "Afwezigheid dit schooljaar" | `kpi` block over `parentAttendanceSummary` | figure cards (exists, `KpiCards`) |
| "Nieuwste cijfers" | `collection` block, `limit: 3`, `sort: gradedAt desc` | list with "show all" link (`site-mijn-omgeving-components`) |
| "Berichten van school" | `inbox` block, `limit: 2` | inbox list (exists), data badge "Nieuw" (`site-mijn-omgeving-components`) |
| Menu grouped per child | `menu.group`, `menu.perRecord`, `menu.hidden` per page | side navigation groups (`site-mijn-omgeving-components`) |
| Absence form | `fieldConfigs.widget` hints, the action | radio cards, quick dates, error summary (`site-multi-step-forms`) |
| Signed-out hero, cards, news | seeded page content | hero, card grid, news widgets (`site-nlds-widget-palette`) |

Portaliq's `site-resident-menu` groups items by app today and says outright that "a manifest field for a page's group" is out of its scope. The `menu` keys below are that field. Lane pq owns the decision on their final shape. Learniq adapts to the names pq picks; the meaning stays.

## The overview page

```
id: parentOverview        label: Overzicht        menu.group: top
records: parentChildren   (one child at a time, the switcher picks)
blocks:
  tasks      parentConferenceRounds  (open, child invited, deadline bookingClosesAt)
  cta x4     createExcuseRequest | parentConferenceFreeSlots page | parentGrades page | portaliq new conversation
  calendar   childSources(), range: week
  kpi        parentAttendanceSummary (the three cards of portal-parent-child-record)
  collection parentExcuseRequests  limit 1  sort dateFrom desc   ("Uw melding van 1 oktober: ...")
  collection parentGrades          limit 3  sort gradedAt desc
  inbox      parentInbox           limit 2
```

The overview reuses the child sources and the figure cards of `ParentRecordPage`. It adds no new scope. Every block reads a collection that already goes through the reverse join on the guardian's children, or the new inbox collection, which uses the same join.

The child switcher shows the given name and the group. The group name is not on `learner-profile`. It comes from `parentGroupMemberships` (`enrolment.cohortId`), resolved to the cohort's `name`. If the cohort name cannot be read through the portal, the switcher shows the given name only. That is acceptable; the group label is a nicety.

## The menu

| Group | Entries |
|---|---|
| top | Overzicht, Berichten (portaliq inbox), Agenda (`parentCalendar`) |
| per child (`perRecord: parentChildren`) | Afwezigheid, Cijfers en rapporten, Oudergesprekken |
| account | Mijn gegevens, Meldingen instellen (both portaliq) |

"Cijfers en rapporten" is the record page of the child, scrolled to the report cards. "Afwezigheid" is a new page per child: the form, the latest three reports, the figures. "Oudergesprekken" is the free-times page with the booking form, followed by the bookings and times of that child.

Every page that `ParentPortalCollections::pages()` builds today keeps its id and its route `/mijn/learniq/<collection>`. Those pages get `menu.hidden: true`. A bookmark keeps working; the menu stops listing them.

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

The action `createExcuseRequest` already has the fields and the child cross-check. This change adds presentation hints only:

- `learnerRef`: radio cards with given name and group, not a select.
- `reasonKind`: three radio cards ("Ziek", "Dokter of tandarts", "Een andere reden"). "Een andere reden" maps to `other` and opens the `reason` text. `illness` and `medical-appointment` fill `reason` with their own label when the guardian leaves it empty.
- `dateFrom`, `dateTo`: quick choices for today and the next school day, then a date input.
- `successMessage`: "De juf of meester heeft uw melding ontvangen."

`reason` stays required in the schema. The hint fills it; it does not relax the rule.

## Open decisions for Ruben

1. Persona name. The persona file is `yasmina-hulstkamp`, to keep her apart from Fatima El-Amrani. The po example set and the e2e seed her as Fatima Hulstkamp. Rename the seed, or keep it?
2. The teacher's comment on a grade. Keep it staff-only (this change), or show it to guardians?
3. Permission slips. Write `school-trip-permission` as the next change?
