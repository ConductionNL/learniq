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

## Calendar, contacts, forms and deck (links, never copies)

Six detail pages carry a leaf from a Nextcloud app next to their own data. Each leaf links something that lives in its own app to the learniq record; nothing is copied into the register, and a leaf only shows when its app is installed.

| Leaf | Schema | Page | What you link |
|---|---|---|---|
| Calendar | Session | Session | room changes, preparation meetings, excursions |
| Calendar | Assignment | Assignment | deadline checkpoints around the due date |
| Calendar | Credential | Credential | renewal planning before the expiry date |
| Forms | Assignment | Assignment | a structured intake form next to the briefing files |
| Contacts | LearnerProfile | Learner profile | the learner's or a guardian's contact card |
| Contacts | Praktijkopleider | Praktijkopleider | the practical trainer's contact card |
| Deck | BpvPlacement | BPV placement | follow-up cards: visit planning, contract chase, company feedback |

What stays off, and why:

- Course, programme, curriculum plan, course template and regulation are catalogue definitions and carry no leaf beyond files. Their sessions have the dates.
- Polls, a class agenda and class sign-up forms are communication, which lives in portaliq (decision D1).
- Email, maps, photos, shares, bookmarks, collectives, notes, activity, time tracking and analytics: no teaching task asked for them, and learniq keeps no learner photos.
- A calendar leaf never creates events from `startsAt`, `dueAt` or `expiresAt`. Those fields stay the source; the leaf holds what people add around them.

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

## Portaliq: who signs in how

Learniq contributes pages to a portaliq portal for four audiences. Each audience can only reach its pages through a sign-in mode the portal offers, so a portal must offer the mode of every audience it serves. The example portals declare these modes in `lib/Settings/portals/<set>.json`.

| Audience | Who | Sign-in mode | How the account is made |
|---|---|---|---|
| `parent` | A guardian | `digid` | The school invites the guardian (`occ learniq:portal:invite-guardian`) |
| `student` | A pupil or student | `nextcloud` | The school account the pupil already has |
| `praktijkopleider` | A workplace trainer | `nextcloud` (eHerkenning once the portal has a broker for it) | `occ learniq:portal:invite-trainer` |
| `external-assessor` | An outside assessor | `nextcloud` | `occ learniq:portal:invite-assessor` |

The example portals: po `wilgenboom` offers `digid`; vo `vaartveld` offers `nextcloud` and `digid`; mbo `esdoornveen` and training `warmtepompacademie` offer `nextcloud` and `eherkenning`. A school may change its own portal's modes. If it removes a mode, the audience that uses it has no way in.

### Loading an example set from the command line

`occ learniq:example-set:load <set>` loads an example set the way the setup wizard does: the objects, then the portal with its menus, pages and news. It also gives the staff the portal names a Nextcloud account with their display name (skip that with `--no-accounts`). A new account gets a random password; set a real one or send an invitation. Run it again and it adds nothing.
