# Migration: store-rights-for-teachers

## Current State
The ADR-023 matrix lives in app config `learniq` / `actions` as JSON. On an instance that
installed learniq before this change it holds `course-package.share: ["admin"]` (the old
seed) and no `course-store.install` row, which `getAllowedGroups()` reads as `["admin"]`.

## Target State
`course-store.install: ["admin", "instructors", "team-leads"]` and, unless an
administrator had changed it, `course-package.share: ["admin", "team-leads"]`. App config
`learniq` / `store_rights_defaults_applied` records that the step ran.

## Migration Class
No database migration. A repair step, because learniq's version comes from the release
workflow and every data fix-up in learniq is a repair step with its own idempotency check:
```
File: lib/Repair/ApplyStoreRightsDefaults.php
Registered: appinfo/info.xml <post-migration>, after InitializeActions
Key operations:
- read the marker; stop when set
- read the matrix; stop (without the marker) when it is empty
- add course-store.install from lib/actions.seed.json when absent
- replace course-package.share with its seed value when it is exactly ["admin"]
- write the matrix when changed; set the marker
```

## Migration Steps
1. Stop when `store_rights_defaults_applied` is set.
2. Read the matrix. An empty matrix means the seed did not run; leave it to `InitializeActions` and do not set the marker.
3. Read the two seed values from `lib/actions.seed.json`; stop without the marker when the file is missing or unreadable.
4. Add or replace the rows as above and write the matrix only when a row changed.
5. Set the marker.

## Data Impact
One app config value per instance, at most two rows of it. No objects, no data loss. Safe
on live data: the matrix is read at request time.

## Rollback Procedure
Set the rows back under Admin settings, Learniq, Action authorization, or with
`occ config:app:set learniq actions --value='<json>'`. Deleting the marker would make the
step run again on the next upgrade.

## Validation
`occ config:app:get learniq actions` shows both rows; `occ config:app:get learniq store_rights_defaults_applied`
answers `1`. `ApplyStoreRightsDefaultsTest` covers the untouched, customised and
already-applied cases.
