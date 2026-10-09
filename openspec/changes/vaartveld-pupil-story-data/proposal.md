---
kind: spec
depends_on: [example-sets-are-the-four-schools, guardian-tasks-per-child-and-self-assessment]
---

# Proposal: vaartveld-pupil-story-data

## Why

Portal proof run 3 (9 October) put Noor Bakker's pages beside the Vaartveld boards (MijnOverzicht, MijnLijst, Detail, Berichten, MobielHome, MobielDetail). Most of what the boards show is already in the vo set: her Monday lessons with the room change and the cancelled lesson, the homework of week 41, her grades and averages, her absence, the mentor-talk times. Two things the boards show are missing from the data itself:

- MobielDetail gives the economie lesson the short reason "Niet in lokaal 1.08". The set stores a long sentence ("Lokaal 1.08 is vandaag niet beschikbaar; economie is in lokaal 0.21."), which repeats the room the pupil reads beside it.
- Berichten opens with "Kies een tijd voor het mentorgesprek". `ConferenceInvitations` writes one invitation per invited pupil when a round is saved, but a seed import saves nothing through that listener, so the vo set has no invitations: no parent or pupil task asks for a time. The po set already seeds them.

## What changes

`scripts/example-sets/vo.py`, and `lib/Settings/profiles/vo.json` regenerated:

- The economie lesson of Monday 5 October: `changeReason` "Niet in lokaal 1.08".
- `conference-invitation` rows for the H4b mentor-talk round, the way po.py writes them: booked for the two classmates who have a time, open for the 25 others, Noor among them. The schema is appended to the set's schema order, so no existing uuid moves (27 objects more, 8074).

## Not in this change (lane FIX-L: shared providers, schemas and strings)

What the pupil boards show that the data cannot carry today:

- **Teacher names per lesson and per subject** ("mevrouw Kramer", "meneer Demir", "Mevrouw Jansen" on MijnLijst): a session and a grade carry no teacher, and staff carry no form of address. Needs a readable copy (for example `session.teacherName`, `grade-entry.teacherName`) and a salutation on staff.
- **What a grade was for** ("Leestoets", "SO hoofdstuk 3" under "Laatste cijfers"): the component label lives in the curriculum plan; a grade needs a readable copy of it. `methodName` is for method tests and is not used for this.
- **The subject of a homework row** ("Vandaag · Nederlands"): an assignment carries `courseId` only.
- **CKV and LO in words** ("voldoende" / V, "goed" / G): the grades are stored on the O/V/G band scale as 2 and 3; the portal needs the band's label.
- **The pupil's own messages**: the roosterwijziging and the grade note on Berichten have no pupil-facing record. `studentInbox` reads the parents' `grade-notification` rows (two per grade with two parents), and a notice never carries the grade, where the board says "Je hebt een 6,9".
- **A pupil-facing invitation**: the rows this change seeds are read by the guardian's pages; the pupil's "Mentorgesprek" page and the slot picker on Berichten need a pupil collection.
- The overview, list, menu and phone declarations themselves live in `StudentPortalPages` (shared with the mbo student) and in portaliq's PQ-MIJN stack.

## What an instance that loaded the set before sees

A reload in the same week adds the 27 invitations (the import skips what exists). The economie lesson keeps its old reason: remove the set and load it again to get the new one.
