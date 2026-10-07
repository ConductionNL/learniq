# Proposal: the teacher's conference and school calendar screens read words, not codes

## Why

Seen on the primary-school instance in the review of 2026-10-03:

- The lists of conference rounds, conference slots and school events showed every schema property: the tenant id, uuids of rounds, signups and learner profiles, the Nextcloud user ids of teacher and pupil, and the school event's start as an ISO timestamp ("2025-12-18T17:30:00+01:00").
- A round's status read as stored ("booking-closed", "scheduled"), and so did a teacher availability's ("submitted"): neither property had labels.
- A slot page opened on "Acknowledged at", "Booked at", "Conference Round ID" and "Pupils who may book": every field, in key order.
- The slot page's Related panel listed "120", "43" and "122". The cause is in `@conduction/nextcloud-vue` (ConductionNL/nextcloud-vue#1300): a row fell back to the numeric schema id. With that fix a row shows the schema's title, so the titles must read as words: the learner profile schema was titled "LearnerProfile", and none of the conference schema titles had a Dutch entry.

## What changes

- The conference rounds, conference slots and school calendar lists name their columns. No column shows a tenant, a uuid or a user id. The school calendar reads its start and end as dates.
- The slot page shows time, teacher, status, start, end, location, round, reason for declining, booked at and acknowledged at, in that order.
- A round page lists its slots by time and teacher name, not by the teacher's user id.
- The round status and the teacher availability status get labels. Slot field titles read "Conference round", "Starts", "Ends" and "Status".
- The learner profile schema is titled "Learner profile". Twelve new catalogue keys with their Dutch.
- Register 0.34.38; the four touched schemas each go up one patch.

## Not changed

- No enum value, no property, no stored data.
- The pupil on a slot is still the Nextcloud user id where it shows. The learner profile schema has no name field (`objectNameField`), so a reference to a pupil cannot read as a name yet. Listed as a follow-up.
