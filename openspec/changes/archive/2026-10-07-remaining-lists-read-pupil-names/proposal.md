# Proposal: the remaining staff lists read a pupil by name

## Why

Seen on the primary-school instance on 2026-10-05, after `lists-read-pupil-names`: the attendance flags, the exam accommodations and the BSA flags still read `po-leerling-139` under "Leerling-ID". That change named them as a follow-up.

The cause is the same on all three. An index page that declares no columns shows every property of its schema. The pupil's Nextcloud user id is one of them, next to the tenant id and every uuid. The guard of `lists-read-pupil-names` only read declared columns, so a page without any passed it.

Counting the index pages on a schema with a pupil user id and no columns gives 41, not three.

The parent portal's list of a child's absences came in no order: 1 October, 5 October, 2 October, 25 September. The collection declared none.

## What changes

- The attendance flags, exam accommodations and BSA flags index pages declare a teacher's columns: the pupil first, by name (`learnerName`), then what the signal or accommodation is about. A group, an assessment and a programme read by name.
- The other 38 index pages keep the columns they showed and read the pupil by name. They lose the tenant id and the fields that only repeat the pupil (`learnerRef`, `learnerRefs`, `learnerUserId`). Where the schema stores the learner profile itself (credentials, outside training, exemption and fraud cases, dossier reviews) the column uses `fkResolve`, as `lists-read-pupil-names` did. A course and a group on these lists read by name.
- A lesson's affected learners and an instruction group's pupils are lists of user ids. They read as names, comma separated.
- `parentExcuseRequests` declares `defaultSort: { field: dateFrom, direction: desc }`, so the newest absence comes first.

## Not changed

- No schema, no stored data. Register version unchanged.
- Uuid columns other than course and group on the 38 pages. They showed a uuid before and still do.
- `regulation-exemption`: its `learnerId` has no format and no row exists to say what it holds.
- Lists that show a pupil only as a profile uuid (assignments, placement visit reports, the converted profile of an application). They show no pupil code.

## Depends on

The absence order needs portaliq to read a collection's `defaultSort` (portaliq change `site-tables-read-in-their-declared-order`). Until that lands the key is carried and ignored, as before.
