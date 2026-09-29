---
kind: code
depends_on: []
---

# Let a learner report a concern confidentially to the counsellor

## Why

A learner who is bullied or harassed has no way in learniq to tell the school's confidential counsellor (vertrouwenspersoon). The counsellor side exists: `2026-09-28-confidential-counsellor-channel` gave the counsellor the `confidential-counsellors` scope and `ConfidentialNote`, readable by nobody else. The learner side does not. Today a learner has to walk up to someone, mail a teacher, or not report at all.

Ruben reversed the rule's decided-no on 29 Sep 2026 (build-all DECISIONS row 18): "BUILD: learner-side confidential report landing in the existing counsellor channel".

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `sup-report-a-concern-confidentially` | Let a learner report bullying or harassment confidentially. | `no`: the counsellor channel is the counsellor side only |

### Demand

- `sup-report-a-concern-confidentially`: roadmap, https://github.com/chamilo/chamilo-lms/issues/8373 ("Enable users to report cases of harrassment, bullying, delinquency by only showing reports to the admin anonymously by default").

### Competitors

No competitor rated yes. Moodle and Chamilo are partial (an anonymous survey or a staff ticket, no case routing); ILIAS is no; Moodle Workplace, Totara and iSpring Learn are unknown. Evidence in the matrix row.

## What changes

- A new schema `ConcernReport` (slug `concern-report`): what it is about, what happened, when, whether the learner wants a conversation, a status the counsellor keeps, the reporter, the tenant.
- Every signed-in user can create one. Only members of `confidential-counsellors` and the reporter can read it. Only counsellors can change or delete it.
- A listener stamps the reporter from the session on create and keeps it unchanged on every update, so nobody can file a report in another person's name or point it at someone else.
- On create the counsellors get a Nextcloud notification that names no one.
- The learner gets a "Report a concern" menu entry with a form and a list of their own reports and their status. The counsellor gets a "Reports" entry next to "Confidential notes".

## Capabilities

### Modified capabilities

- `confidential-counsel`: adds the learner's report and the counsellor's inbox.

## Out of scope

- Anonymous reports, hidden even from the counsellor. OpenRegister records who created an object (`@self.owner`, the audit trail), so a report cannot honestly promise that. The form says who will see the name.
- A portal (portaliq) entry for learners without a Nextcloud account.
- A link from a report to a `ConfidentialNote`. The counsellor opens a note by hand and may add the reporter as a participant, as today.

## Impact

- `lib/Settings/learniq_register.json`: the schema, its authorization, one notification, `info.version`.
- `lib/Listener/ConcernReportReporterStamp.php` (new), registered in a listener registrar.
- `src/manifest.d/confidential-counsel.json`: two menu entries, three pages.
- `l10n/` every shipped locale.
- Tests: register shape, the stamp, the registrar wiring.

## Risks

- Nextcloud admins bypass OpenRegister access rules and can read every report, as with `ConfidentialNote`. The schema description and the form say so; a school keeps `admin` to IT staff.
- The audit trail keeps a deleted report's content (the same OpenRegister limit named in the counsellor channel design).
- A school with no member in `confidential-counsellors` receives reports nobody reads. The form shows a line saying there is no counsellor yet when the group is empty.
