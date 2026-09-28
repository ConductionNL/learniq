---
sidebar_position: 1
draft: true
---

# Integrations

This section is under construction. Integration guides for Learniq are being authored in Codeberg issue #73 (pre-migration, not migrated to GitHub).

## Nextcloud Talk (live)

Cohort and Session both expose a **Nextcloud Talk** conversation via
`linkedTypes: ["talk"]` — no scholiq-owned Talk client, no conversation token
stored on either object. Linking is handled entirely by OpenRegister's
existing, generic Talk integration (`TalkLinkService` + `TalkLinksController`,
`TalkProvider` id `talk`) and rendered by nextcloud-vue's `CnTalkTab` /
`CnTalkCard` — scholiq only declares the `linkedTypes` and adds one
`integration`/`talk` manifest widget per object (`CohortDetail` "Class space",
`SessionDetail` "Join call"). `Course`, `Programme`, and `CurriculumPlan`
deliberately have no Talk (or any comms) leaf — they are catalog/definition
objects, not delivery instances.

The one piece of scholiq-owned logic is `CohortTalkMembershipHandler`
(`lib/Listener/`): it keeps a Cohort's linked conversation's participant list
in sync with active `Enrolment`s — adding the learner on `activate`, removing
them on `withdraw`. It fails soft (logs, no-ops) when Talk (`spreed`) is not
installed, or the Cohort has no conversation linked yet.

**Known limitation**: learners whose `Enrolment` was already `active` before
a conversation was linked to their Cohort are **not** retroactively added —
OpenRegister fires no "room linked" event to hook into. A coordinator who
links a Cohort's conversation after learners are already enrolled adds that
initial batch once via Talk's own participant UI; every enrolment change
after that point stays in sync automatically.

## Data exchange through Integriq

Integriq sends learniq's reports and files to the outside world: DUO ROD, the
Verzuimloket, OSO transfer files, UWLR, Edu-V, Basispoort and the
samenwerkingsverband. Learniq decides what may leave.

Before a job runs, Integriq asks learniq. Learniq checks that a parent approved
an OSO or SWV file, that the partner link is approved, that the teldatum count
is confirmed, and that someone has taken up a verzuim signal. Only the fields
the job's mapping needs leave the school, and never an email address.

The BSN leaves the school in one place only: DUO's register (ROD). DUO
identifies a pupil by the BSN, or by the onderwijsnummer when the pupil has no
BSN. Store it on the learner profile under **Personal number**. Learniq keeps
it encrypted, and only administration managers and compliance officers can
see it. Each view is recorded with who viewed it. It never appears in
another export or in a log.

A school advice goes to ROD with the set DUO asks for: the voorlopig and
definitief advice with their dates, the school year, and the school location.
Give the advice its school location when your school has more than one. Fill
in the onderwijsaanbieder code on the school. Nothing else from the pupil's
file goes with it.

Follow the jobs under **Data exchange > Exchange jobs**. A record the other
side rejected shows under **Rejections**; fix it in learniq, then resubmit it
in Integriq. Parents decide on a file under **Parent reviews**.

Without Integriq nothing is sent, and learniq says so.

Planned integrations include:

- **OpenRegister**: data layer (required)
- **Integriq**: BRON/ROD, Verzuimloket, UWLR, Edu-V, OSO and SWV adapters, run as jobs learniq asks for (required for data exchange)
- **LaunchPad**: student and credential analytics surfaces (recommended)
- **DocuDesk**: diploma and certificate document templating (optional)
- **SURFconext**: SSO federation for higher education
- **DUO BRON/ROD**: student registration exchange

Follow Codeberg issue #73 (pre-migration, not migrated to GitHub) for progress.
