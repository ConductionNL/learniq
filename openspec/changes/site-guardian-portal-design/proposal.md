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

- **Overview first.** A new guardian overview page opens after sign-in. It shows a child switcher, "Dit moet u nog doen", quick actions, the week, the absence figures, the newest grades and the newest messages for the chosen child.
- **Menu per child.** Pages are grouped: "Overzicht", "Berichten" and "Agenda" on top, one group per child with that child's sections, then "Uw account". The fifteen collection pages stay reachable by their old routes but leave the menu.
- **Tasks first.** An open conference round the child is invited to shows as a task with its last booking day.
- **Quick actions.** Report sick, book a conversation, open grades and report cards, write to the teacher. Each is a `cta` block on an action or page that exists today.
- **The absence page.** The form first, then only the three latest reports of that child, then a link to all of them.
- **Grades name their subject and weight.** New, see below.
- **The signed-out home** of the po example portal is seeded with the mockup's content. New, see below.

New work, clearly marked in the specs:

- **NEW: a readable subject on a grade.** `GradeEntry` holds `courseId`, not a name. A server-stamped `courseName` copy, the pattern `ReportCard.periodName` already uses, lets the portal show "Rekenen, toets breuken". `weight` is added to the guardian's projection.
- **NEW: the guardian's inbox of school notices.** `report-card-parent-notification` and `grade-notification` rows carry `learnerRef`. A `kind: inbox` collection through the child join lets "Het rapport van Vera staat klaar" reach the portal inbox.
- **NEW: a seeded signed-out home** for the po example portal, written by `ExamplePortalProvisioner` only when it creates the portal.
- **NEW: a second child in the po example set.** Fatima Hulstkamp has only Vera today. The child switcher needs Sami (groep 3).

## Depends on

Portaliq (lane pq of the portal-design programme writes these; this change only references them):

- `site-mijn-omgeving-components`: the child switcher, the task row with a deadline, the quick-action tiles, the figure cards, the "show the latest N, link to all" list. It also has to accept the keys this change declares, none of which portaliq's `PortalPageResolver` or `PortalBlockResolver` knows today: the page keys `menu.group`, `menu.hidden`, `menu.perRecord` and `records`; the block types `tasks` and `inbox`; `limit` and `sort` on a `collection` block; `range` on a `calendar` block; `widget` in an action's `fieldConfigs`. If that change leaves any key out, the gap is named in its tasks and this change waits on it.
- `site-nlds-widget-palette`: the hero, card grid and news widgets the seeded signed-out home places.
- `site-multi-step-forms`: the radio-card and quick-date inputs of the absence form, the error summary and the confirmation.

Learniq:

- `portal-parent-child-record` (the record page, the figures, the calendar) and `direct-conference-booking` (the booking forms). Both are on development.

## Not in this change

- **Permission slips** ("Geef toestemming voor het schoolreisje" in `Main.dc.html`). No schema holds a request for consent or its answer. `SchoolEvent.kind = trip` exists, and `LearnerProfile.beeldmateriaalConsent` covers image use only. A permission slip needs a new record, a staff screen to send it and a signed answer. That is its own change, proposed as `school-trip-permission`.
- **Writing to the teacher.** Portaliq owns conversations ("Gesprekken"). The quick action links to portaliq's new-conversation page. Learniq declares no message schema.
- **The teacher's comment on a grade** ("De juf schreef er een opmerking bij"). `GradeEntry.comment` is dropped from every portal projection on purpose (staff-only, portal-contribution design). Showing it to guardians is a privacy decision for Ruben, not a design detail.
- **The login choices** on the signed-out home (DigiD for guardians, school account for pupils, a third entry for companies and assessors). Portaliq's auth edge owns which brokers a portal offers.
- **Dark mode.** Portaliq turns it off on the site on purpose.
- **Notification settings** ("Meldingen instellen"). Portaliq owns the account pages.

## Impact

- `lib/Portal/ParentRecordPage.php`, `lib/Portal/ParentPortalCollections.php`, `lib/Portal/PortalContributionProvider.php`, `lib/Portal/PortalLabelTranslator.php` and `l10n/nl.json`.
- `lib/Portal/ExamplePortalProvisioner.php` for the seeded home.
- `lib/Settings/learniq_register.json`: `GradeEntry.courseName`.
- `lib/Settings/profiles/po.json`: Sami Hulstkamp.
- No new scope rule. Every new collection reads through the existing reverse join on the guardian's own children.
