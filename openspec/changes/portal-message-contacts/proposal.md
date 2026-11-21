---
kind: code
depends_on: []
---

# Proposal: portal-message-contacts

## Why

The school boards (Wilgenboom and Vaartveld "Berichten", plan `PORTAL-PLAN.md` item W2-5) let a guardian write to the teacher of each child ("Meester Daan, over Vera") and a pupil write to her mentor and teachers. Portaliq's `site-messages-per-record` asks the app who those people are: a collection declares `contacts: {provider, ...}`, and for each row the resident owns portaliq calls that provider method with the row id. Learniq declares no such provider, so no guardian or pupil can start a conversation; portaliq's interim `groupStaffFixture` is not written for a real school.

## What changes

- **`PortalMessageContacts`** (new, `lib/Portal/`): the teachers of a pupil's current groups, the group's primary teacher first, each once, with the display name of their account and their role in words ("Mentor" when the staff record says so, else "Teacher"). A teacher whose account has no name of its own is left out rather than shown as a user id.
  - `childContacts(profileId)`: for a guardian, the groups the child is a current member of (`cohort.learnerIds`, not completed or archived).
  - `ownContacts(enrolmentId)`: for a pupil, the group of one of her enrolments, only while that enrolment is `active`; last year's completed enrolment names nobody.
- **Provider methods** `childMessageContacts($id)` and `ownMessageContacts($id)` on `PortalContributionProvider`, which portaliq calls only for a row it read through the resident's own scoped collection. The service is an optional second constructor argument; `new` with no arguments answers nobody.
- **Declarations**: `parentChildren` gets `contacts` (provider `childMessageContacts`, record label `givenName`, "Een bericht aan de leerkracht" and its hint); `studentEnrolments` gets `contacts` (provider `ownMessageContacts`, "Een bericht aan je docent").
- `composeLabel` and `composeHint` join the translated keys; Dutch entries for the four new strings.

## Not in this change

- **A teacher's screen for these conversations.** Staff reply through portaliq's `/api/staff/messages/*` endpoints today; neither app shows them on a staff screen yet.
- Office staff, the intern begeleider or a praktijkopleider as contacts. Only group teachers.
- Contacts for the `praktijkopleider` and `external-assessor` audiences.

## Impact

- `lib/Portal/PortalMessageContacts.php` (new), `PortalContributionProvider.php`, `ParentRecordPage.php`, `PortalLabelTranslator.php`, `l10n/`.
- No register change, no seed change.
- Depends on portaliq `site-messages-per-record` for anything to show; without it portaliq drops the unknown `contacts` key.
