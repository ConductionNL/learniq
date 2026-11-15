# Migration: gradeentry-learnerref-stamp

## Current State
`grade-entry` rows carry `learnerId` (Nextcloud user id). `learnerRef` exists as a nullable column
and is empty on every row, because no creator writes it.

## Target State
Every row whose learner has a LearnerProfile carries that profile's UUID in `learnerRef`. Rows
without a matching profile keep `learnerRef` empty.

## Migration Class
```
No Doctrine migration: the column already exists.
Data step: lib/Repair/BackfillGradeEntryLearnerRef.php (IRepairStep, <post-migration>)
Key operations:
- page through grade-entry objects, 200 at a time, _rbac:false, _multitenancy:false
- skip rows that have learnerRef or have no learnerId
- resolve learnerId -> LearnerProfile UUID (cached per run)
- saveObject() the row with learnerRef set
```

## Migration Steps
1. Read one page of `grade-entry` objects.
2. For each row without `learnerRef`, resolve the profile; skip when none.
3. Save the row with `learnerRef` set. The stamp listener runs on that update and derives the same
   value.
4. Repeat until a page returns fewer rows than the page size.
5. Log counts: scanned, stamped, skipped without profile.

## Data Impact
One field written on existing rows; nothing removed. Runs on live data; each save is independent,
so an interrupted run resumes on the next upgrade.

## Rollback Procedure
None needed: the values are correct derivations. To undo, set `learnerRef` to null on the rows; the
portal then hides them again, which is today's behaviour.

## Validation
After the step: count `grade-entry` rows with a `learnerId` whose profile exists and an empty
`learnerRef`. Expected: zero. The step's own log line reports the stamped count.
