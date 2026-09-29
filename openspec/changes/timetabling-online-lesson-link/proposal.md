---
kind: code
---

# Join an online lesson from the timetable

## Why

Three competitors let a lesson carry an online meeting link that learners open from their timetable. In learniq the session detail page has a Talk "Join call" widget (`src/manifest.d/learning.json:2668-2672`), but `src/views/MyTimetable.vue:152-155` shows the location as text and `Session` has no link property, so a learner must open the lesson page first and a lesson held on another platform has nowhere to keep its link. The row sits in the planninq matrix and is owned by learniq.

One row, one change.

### Matrix rows (`planninq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `tt-online-lesson-link` | Attach an online meeting link to a lesson so learners can join it from their timetable. | `no`: `building`: the lesson page carries a Talk join widget, but the personal timetable shows the location as plain text and a session has no meeting link field |

### Demand

- `tt-online-lesson-link`: no demand row.

### Competitors rated yes

- `tt-online-lesson-link`, zermelo: "docs read 2026-09-27: https://support.zermelo.nl/guides/applicatiebeheerder/zermelo-en-speyk-microsoft-teams 'Afspraken in onze API krijgen een extra veld onlineLocationUrl waarin een url geplaatst kan worden naar de online locati"
- `tt-online-lesson-link`, untis: "docs read 2026-09-27: https://www.untis.at/webuntis-demo 'Soll eine Unterrichtsstunde als Videokonferenz gehalten werden, so können Sie einfach den Konferenz-Link im WebUntis Stundenplan einbinden' ; https://www.untis.at/produkte/"
- `tt-online-lesson-link`, xedule: "docs read 2026-09-27: https://support.xedule.nl/hc/nl/articles/36260092180114-Beheer-Configuratie-Exchange 'Vanaf Xedule 5.7.5 is ook de GRAPH API beschikbaar ... deze maakt het mogelijk Microsoft Teams meetings aan te maken van l"

## What Changes

- Add `onlineMeetingUrl` to `Session`, editable by the teacher and validated as https.
- Show a Join button on the timetable row and the lesson page when a link is set, and use the Talk room link when the lesson uses the Talk widget.
- Include the link in the calendar feed event when `attendance-timetable-calendar-feed` is built.

## Capabilities

### New Capabilities

- `timetable-online-lesson-link`

### Modified Capabilities

- None in delta form.

## Impact

- **Register**: `Session.onlineMeetingUrl` (string, https, max 2000).
- **Backend**: validation in the session write path; visibility follows the session.
- **Frontend**: `MyTimetable.vue` row action, session edit dialog, `SessionDetail` page.
- **Security**: the URL is rendered as an anchor with `rel="noopener noreferrer"`; scheme is validated on the server and the client.
