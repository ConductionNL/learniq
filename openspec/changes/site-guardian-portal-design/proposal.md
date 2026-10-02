---
kind: code
depends_on: [portal-parent-child-record, direct-conference-booking]
---

# Proposal: site-guardian-portal-design

## Why

Ruben approved twelve mockups for the learniq and dossiq sites on portaliq `/site` (2026-10-02). Three of them show the guardian of a primary-school pupil:

- `LearniqHome.dc.html`: the signed-out home of the Wilgenboom ouderportaal.
- `Main.dc.html`: the signed-in overview for one guardian with two children.
- `LearniqAbsence.dc.html`: the absence form on a phone.

The persona is `yasmina-hulstkamp` in hydra `personas/`. The mockups call her Fatima Hulstkamp, as the po example set does. She has two children, little time, a phone and B1-level Dutch.

The guardian site works today, but it does not read like the mockups:

- The menu lists about fifteen flat entries, one per collection ("My child's grades", "Your conference times", ...). It does not say which child an entry is about.
- "Mijn kinderen" is a record page. The guardian has to open it, then pick a child. Nothing tells her what she still has to do.
- A grade row shows the value, the period and the date. It does not name the subject.
- The absence page shows the form, then every earlier report in one table.

This change makes learniq declare the pages, menu groups, blocks and labels the mockups need. Portaliq draws them. Learniq does not respecify any component.

## What changes

Existing data, new declarations:

- **Overview first.** A new guardian overview page, marked `home: true`, opens on `/mijn` after sign-in. It shows a child switcher, "Dit moet u nog doen", quick actions, the week, the absence figures, the newest grades and the newest messages for the chosen child.
- **Menu per child.** Pages are grouped: "Overzicht", "Berichten" and "Agenda" on top, one group per child with that child's sections, then "Uw account". The fifteen collection pages stay reachable by their old routes but leave the menu.
- **Tasks first.** An open conference round the child is invited to shows as a task with its last booking day.
- **Quick actions.** Four tiles as in `Main.dc.html`: "Vera ziek of afwezig melden" (`createExcuseRequest` with the child preset), "Oudergesprek boeken", "Cijfers en rapport bekijken" (pages, opened for the chosen child) and "Bericht sturen aan de juf" (a portaliq route). Each is a `cta` block (REQ-SMO-024).
- **The absence page.** The form first, then only the three latest reports of that child, then a link to all of them.
- **Grades name their subject and weight.** New, see below.
- **The signed-out home** of the po example portal is seeded with the mockup's content. New, see below.

New work, clearly marked in the specs:

- **NEW: a readable subject on a grade.** `GradeEntry` holds `courseId`, not a name. A server-stamped `courseName` copy, the pattern `ReportCard.periodName` already uses, lets the portal show "Rekenen, toets breuken". `weight` is added to the guardian's projection.
- **NEW: the guardian's inbox of school notices.** `report-card-parent-notification` and `grade-notification` rows carry `learnerRef`. A `kind: inbox` collection through the child join lets "Het rapport van Vera staat klaar" reach the portal inbox.
- **NEW: a seeded signed-out home** for the po example portal, written by `ExamplePortalProvisioner` only when it creates the portal.
- **NEW: a second child in the po example set.** Fatima Hulstkamp has only Vera today. The child switcher needs Sami (groep 3).
- **NEW: the group name under the child.** The switcher reads "Vera, Groep 6" through a one-hop `subtitleLookup`. The group name is two hops away, so `Enrolment.cohortName` becomes a server-stamped readable copy.

## Depends on

Portaliq (lane pq of the portal-design programme writes these; this change only references them):

- `site-mijn-omgeving-components` (portaliq PR #1110, commit 87423edc): the child switcher, the task row with a deadline, the quick-action tiles, the figure cards, the "show the latest N, link to all" list. This change uses its keys as specified there: the page keys `group`, `menu: false`, `perRecord`, `records` with `subtitleLookup`, and `home: true` (REQ-SMO-020, REQ-SMO-026); the block types `tasks` and `inbox` with `recordField`, `limit` and `sort` on a `collection` block, `range` on a `calendar` block (REQ-SMO-021, REQ-SMO-025); `cta` with `page`, `route`, `withRecord` and `{title}` (REQ-SMO-024).
- `site-nlds-widget-palette`: the hero, card grid and news widgets the seeded signed-out home places.
- `site-multi-step-forms` (portaliq PR #1110): `fieldConfigs.widget` values `choices` and `dateChoices` for the absence form, the error summary, and the action key `confirmation: {title, body, next}`.

Learniq:

- `portal-parent-child-record` (the record page, the figures, the calendar) and `direct-conference-booking` (the booking forms). Both are on development.

## Not in this change

- **Permission slips** ("Geef toestemming voor het schoolreisje" in `Main.dc.html`). No schema holds a request for consent or its answer. `SchoolEvent.kind = trip` exists, and `LearnerProfile.beeldmateriaalConsent` covers image use only. A permission slip needs a new record, a staff screen to send it and a signed answer. That is its own change, proposed as `school-trip-permission`.
- **A message schema.** Portaliq owns conversations ("Gesprekken"). The "Bericht sturen aan de juf" tile opens a portaliq route. Lane pq has not named a route that starts a conversation with a teacher; the design names this as an open value.
- **A conference task per child.** The task lists every open round of any of her children. Narrowing it per child would mean projecting `ConferenceRound.invitedLearnerRefs`, which holds other pupils' uuids.
- **Three absence cards.** The form keeps all six kinds as cards for now. "Two cards plus 'Een andere reden'" (`choiceOptions`, `otherLabel`) waits on Ruben's decision.
- **The teacher's comment on a grade** ("De juf schreef er een opmerking bij"). `GradeEntry.comment` is dropped from every portal projection on purpose (staff-only, portal-contribution design). Showing it to guardians is a privacy decision for Ruben, not a design detail.
- **The login choices** on the signed-out home (DigiD for guardians, school account for pupils, a third entry for companies and assessors). Portaliq's auth edge owns which brokers a portal offers.
- **Dark mode.** Portaliq turns it off on the site on purpose.
- **Notification settings** ("Meldingen instellen"). Portaliq owns the account pages.

## Impact

- `lib/Portal/ParentRecordPage.php`, `lib/Portal/ParentPortalCollections.php`, `lib/Portal/PortalContributionProvider.php`, `lib/Portal/PortalLabelTranslator.php` and `l10n/nl.json`.
- `lib/Portal/ExamplePortalProvisioner.php` for the seeded home.
- `lib/Settings/learniq_register.json`: `GradeEntry.courseName`, `Enrolment.cohortName`.
- `lib/Settings/profiles/po.json`: Sami Hulstkamp.
- No new scope rule. Every new collection reads through the existing reverse join on the guardian's own children.
