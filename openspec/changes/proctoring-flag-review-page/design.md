# Design: review proctoring flags in one queue

## Context

At development `c2d3f4ed`:

- `ProctoringSession` (`lib/Settings/learniq_register.json`, schema `proctoring-session`): `assessmentResultId`, `learnerId`, `provider`, `status`, `flags[]` with `flagId`, `kind`, `occurredAt`, `severity`, `reviewDecision` (`pending|allowed|annulled`), `reviewedBy`, `reviewedAt`. Read: `instructors`, `compliance-officers`, the learner on their own row. Update: the same two groups, and the learner on their own row while `lifecycle` is `created` or `active`.
- Native test mode appends flags from the learner's browser through that learner update right (`TakeAssessmentView.vue` `appendFlag()`), so the learner's update right has to stay.
- `ProctoringReviewQueue.vue` fetches sessions, keeps those with a pending flag and PATCHes the whole `flags` array with the decision.
- The `assessment` main spec already says native test-mode sessions appear in "the existing ProctoringReviewQueue" (Requirement: Native test-mode events log into the existing ProctoringSession review queue). The page that sentence relies on is gone since `4e49128f`.

## Screen

Board `LqProctoring` on canvas part 2 (`QAAxpcsFKBCvDUGbQbwtCa`), title "Proctoring: vlaggen beoordelen".

- Breadcrumb "Lessen en opdrachten / Toetsen / Proctoring" and the count "2 sessies met openstaande vlaggen".
- Intro: "Beoordeel gemarkeerde proctoring-gebeurtenissen. Beslissingen worden vastgelegd voor de compliance; er wordt geen resultaat automatisch gewijzigd (EU AI-verordening, artikel 14)."
- One card per session: heading "Sessie bij {test}", badge "{n} openstaande vlaggen", line "Leerling: {name} · aanbieder: {provider}".
- One row per flag: the event in words ("Van tabblad gewisseld of venster geminimaliseerd", "Volledig scherm verlaten", "Venster verloor focus", "Geopend in een tweede tabblad of venster"), the time ("di 6 okt 10.14, na 12 minuten"), buttons "Toestaan" and "Annuleren". A decided row shows "Toegestaan door {name} op {date}" instead of buttons.
- Footer: "Het annuleren van een vlag maakt het resultaat niet automatisch ongeldig. Gebruik de pagina van het toetsresultaat voor een volgende stap."

## Decisions

### D1: Bring the existing component back, do not rebuild it as a typed page

The page groups flags per session with per-flag buttons. No typed page in nextcloud-vue draws an array of sub-objects with actions per entry, and the component already does the read and the write. It stays a `custom` page; the manifest gets it back with a menu entry.

### D2: The server owns the decision fields

A browser check cannot stop a learner who holds an update right. A pre-write listener on `proctoring-session` (the pattern `ElectiveSignUpRules` uses for writes OpenRegister runs no guard on) compares the incoming `flags` with the stored ones:

- A caller outside `instructors` and `compliance-officers` may only append flags whose `reviewDecision` is `pending` or absent; changing or removing a stored flag is refused.
- A staff caller may change `reviewDecision` from `pending` to `allowed` or `annulled`, once. The listener sets `reviewedBy` to the caller and `reviewedAt` to the server time, whatever the body said.
- A write with no user (the system) or by an admin is not checked, as in the other listeners.

### D3: Names, not ids

The card resolves `learnerId` to a display name and `assessmentResultId` to the assessment title through the objects the reviewer can already read. When a lookup fails the card shows the id, never an empty line.

### D4: Minutes into the attempt

"na 12 minuten" is `occurredAt` minus the result's `startedAt`, rounded down. When the result has no start time the minutes are left out.

### D5: Flag kinds in words

| kind | English | Dutch |
|---|---|---|
| `tab-hidden` | Switched tab or minimised the window | Van tabblad gewisseld of venster geminimaliseerd |
| `fullscreen-exit` | Left full screen | Volledig scherm verlaten |
| `window-blur` | Window lost focus | Venster verloor focus |
| `concurrent-session-detected` | Opened in a second tab or window | Geopend in een tweede tabblad of venster |
| `blocked-navigation` | Tried to leave the test page | Probeerde de toetspagina te verlaten |

An unknown kind (an external provider's own) is shown as sent.

## Risks

- The guard compares arrays. Flags are matched on `flagId`; a stored flag without one cannot be matched and is treated as unchangeable.
