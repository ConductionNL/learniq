# Contract: segment-wizard-choice

Two interfaces: the **example set descriptor** that six data set lanes write against, and the **setup endpoints** the shared `CnSetupWizard` calls. `ExampleSetDescriptorContractTest` (tests/Unit/Settings/) enforces the first mechanically; a file that fails it does not ship.

## Consumers
- `learniq` data set lanes (round 2, D21): `segment-example-datasets-po` (this lane), and one sibling lane each for `vo`, `mbo`, `he`, `corporate`, `training`. Each adds exactly one file.
- `@conduction/nextcloud-vue` `CnSetupWizard` / `CnAppRoot` (reads the setup status document, posts answers and actions).
- `tests/e2e/ci-seed.sh` and `tests/e2e/spec-coverage/demo-data-setup-step.spec.ts`.

## 1. The example set descriptor

### File
`lib/Settings/profiles/<id>.json`, one per set, where `<id>` is one of:

| id | segment | order | set |
|---|---|---|---|
| `po` | `po` | 1 | primary school |
| `vo` | `vo` | 2 | secondary school |
| `mbo` | `mbo` | 3 | MBO |
| `he` | `he` | 4 | HBO/WO |
| `corporate` | `corporate` | 5 | company |
| `training` | `training` | 6 | training institute |

The subdirectory is load-bearing: OpenRegister's `RegisterDescriptorService` scans `lib/Settings/*.json` non-recursively and indexes by declared register, so a profile beside `learniq_register.json` would collide with it. Strict JSON, 2-space or 4-space indent, UTF-8, trailing newline.

### Top-level shape
```json
{
    "openapi": "3.0.0",
    "info": {
        "title": "Learniq example set: Primary school",
        "version": "1.0.0",
        "description": "One sentence: which organisation this set shows."
    },
    "x-openregister": {
        "type": "profile",
        "app": "learniq",
        "profile": {
            "id": "po",
            "segment": "po",
            "label": "Primary school",
            "description": "One sentence for the wizard card. No numbers that change, no em-dashes.",
            "order": 1,
            "objectCount": 0,
            "icon": "SchoolOutline"
        },
        "description": "Why this file looks like this (copy the paragraph from po.json).",
        "seedData": {
            "description": "What the objects add up to.",
            "objects": {
                "<schema-slug>": [ { "...": "objects, see below" } ]
            }
        }
    },
    "paths": {},
    "components": {}
}
```

Required keys and their rules:

| key | rule |
|---|---|
| `x-openregister.type` | exactly `profile` |
| `x-openregister.app` | exactly `learniq` |
| `profile.id` | equals the file name without `.json` |
| `profile.segment` | equals `profile.id`; one of `SegmentService::SEGMENTS` |
| `profile.label` | sentence case, English source; add en and nl keys to `l10n/` |
| `profile.description` | one sentence, English source; add en and nl keys; no interpolated numbers (the wizard translates it by literal lookup) |
| `profile.order` | the number in the table above |
| `profile.objectCount` | the exact number of objects in `seedData.objects` (the test counts) |
| `profile.icon` | a name registered in `src/icons.js` |
| `components` | `{}`: no `registers`, no `schemas`. A declared register would make `ImportHandler::importRegister()` re-point learniq's register at this profile's config id and overwrite its `authorization` block (decidesk seed-profiles, verified live) |
| `seedData.objects` | a map of learniq schema slug (`components.schemas.*.slug` in `learniq_register.json`) to a list of objects |

### Objects
```json
{
    "@self": { "configuration": "learniq", "register": "learniq", "schema": "cohort" },
    "uuid": "ee010004-0000-4000-8000-000000000003",
    "slug": "po-cohort-003",
    "name": "Groep 3",
    "period": "Schooljaar",
    "academicYear": "2025-2026",
    "locationId": "ee010002-0000-4000-8000-000000000001",
    "tenant_id": "00000000-0000-4000-8000-000000000000"
}
```

| rule | detail |
|---|---|
| `@self` | `configuration: "learniq"`, `register: "learniq"`, `schema` equal to the bucket key |
| `uuid` | fixed, unique across the file, inside the set's namespace (below). The importer uses it as the object's id and matches on it, so a second load adds nothing |
| `slug` | `<id>-<schema-slug>-<NNN>`, unique across the file |
| properties | only properties the schema declares (gate 108); every `required` property present; enum values from the schema; `date`, `date-time` and `uuid` formats valid; patterns matched |
| references | any property that is `format: uuid` or `$ref` (or an array of them) holds the `uuid` of an object **in the same file**, or null. Never a uuid from another set, never a random one |
| people | Nextcloud user id fields (`learnerId`, `teacherId`, `ncUserId`, `authorId`, `markedBy`, `raisedBy`, `coordinatorId`, `submittedBy`, `parentIds[]`, ...) hold fictional ids `<id>-<role>-<NNN>`, for example `po-leerling-001`, `po-leerkracht-01`, `po-ouder-001`. They name no real account |
| `tenant_id` | the constant `00000000-0000-4000-8000-000000000000` (the generated demo register's value) |
| lifecycle fields | only a value the schema's lifecycle declares; seeding runs as a system operation, so no lifecycle guard or listener fires and no derived value is computed: write consistent values yourself |
| forbidden | no `LearniqSettings` objects (the segment is the wizard's answer, not the set's); no real BSN (leave `bsnEncrypted` unset); no real school, BRIN, person or address; no `id` key (the importer ignores it; use `uuid`) |
| order | parents before children (a school before its locations, a cohort before its enrolments). Removal purges in reverse file order |

### Fixed uuid namespace
`ee<SS><TTTT>-0000-4000-8000-<NNNNNNNNNNNN>`

- `ee`: marks example data.
- `SS`: the set: `01` po, `02` vo, `03` mbo, `04` he, `05` corporate, `06` training.
- `TTTT`: a schema number chosen by the set (hex, `0001` upward), so a uuid shows its schema at a glance.
- `NNNNNNNNNNNN`: a sequence number within the schema, `000000000001` upward.

Two sets can therefore never collide, and the contract test rejects a uuid outside the set's `ee<SS>` prefix.

### Dates
Every set tells the same school year: **2025-2026** (Monday 18 August 2025 to Friday 10 July 2026), finished and complete, so attendance, report periods and test moments all fall inside it. Timestamps carry an explicit offset (`2025-09-01T08:30:00+02:00`).

### Fictional school codes
A BRIN is `^[0-9]{2}[A-Za-z0-9]{2}$`. DUO assigns two digits plus two letters, so a set uses a code with a digit in the last position, which DUO never assigns: `00X1` (po), `00X2` (vo), `00X3` (mbo), `00X4` (he). Vestiging codes append two digits (`00X100`).

### How a set is selected, loaded and removed
1. **Listed**: `SeedProfileService::listChoices()` reads every `lib/Settings/profiles/*.json`, keeps the ones with a valid `x-openregister.profile` block, sorts by `order`, and returns `none` first and the generated set (`demo`) last. A malformed file is logged and skipped.
2. **Selected**: the wizard's `example-set` step posts `{"example_profile": "<id>"}` to `POST /api/setup/config`; the server accepts only an id `listChoices()` offers.
3. **Loaded**: the `load-example-set` step posts `POST /api/setup/action/load-example-set`; the server imports the file through `ConfigurationService::importFromApp(appId: "learniq.profile.<id>", data: <file>, version: <app version>, force: true)`. Seeding runs as a system operation (no lifecycle events).
4. **Removed**: the wizard's `remove-example-set` step calls OpenRegister's `ConfigurationService::softDeleteAppImports("learniq.profile.<id>")` (`learniq.demo` for the generated set), which soft-deletes what the recorded import jobs created and leaves what they only updated (openregister PR 4080; example-set-removal-in-wizard). On an OpenRegister without that method, or for a set loaded before import jobs were recorded, `occ learniq:example-set:remove <id>` lists the file's uuids, last-loaded first, and hands them to `occ openregister:objects:purge --force` (plus `--apply` when given). Nothing else is touched; a record a user created against an example object stays.

## 2. Setup endpoints

### `GET /apps/learniq/api/setup/status`
**Auth**: Nextcloud session, admin (`#[AuthorizedAdminSetting(AdminSettings::class)]`).

**Response (200):**
```json
{
    "version": 2,
    "completed": true,
    "profiles": [
        { "id": "none", "label": "None, I will set this up myself", "description": "...", "objectCount": 0, "icon": "CloseCircleOutline" },
        { "id": "po", "label": "Primary school", "description": "...", "objectCount": 0, "icon": "SchoolOutline" },
        { "id": "demo", "label": "Every schema, generated values", "description": "...", "objectCount": 0, "icon": "DatabaseOutline" }
    ],
    "segments": [
        { "id": "po", "label": "Primary school", "description": "...", "icon": "SchoolOutline" }
    ],
    "steps": {
        "example-set": { "done": false },
        "load-example-set": { "done": false },
        "segment": { "done": false },
        "remove-example-set": { "done": true }
    }
}
```
`datasets` is no longer served; nothing in the manifest reads it.

### `POST /apps/learniq/api/setup/config`
**Auth**: admin. Only the named keys are read; any other posted key is ignored.

| key | accepted values | effect |
|---|---|---|
| `example_profile` | an id from `profiles` (string, or a one-element list) | stored in app config `example_profile` |
| `demo_dataset` (legacy) | as above | stored in `example_profile` |
| `segment` | one of the six segment codes | written to `LearniqSettings.segment` (create or update), `setBy` = the admin, `setAt` = now; only for a member of `admin` or `administration-managers`, else 403 (example-set-removal-in-wizard) |

**Response (200):** `{ "success": true, "config": { "<key>": "<value>" } }`

### `POST /apps/learniq/api/setup/action/{actionId}`
**Auth**: admin.

| actionId | effect |
|---|---|
| `load-example-set` | import the stored `example_profile`; `none` finishes the step without importing |
| `skip-example-set` | store `none`, finish both example steps |
| `remove-example-set` | soft-delete the stored set's recorded imports through OpenRegister; `none` or no answer removes nothing; without the method, or with errors, `success: false` and the occ command that finishes the job. The status reports this step done at all times, so the wizard only runs it on a click |
| `load-demo-data`, `install-demo-data`, `skip-demo-data` | legacy aliases of the three above (`install-demo-data` with no answer means `demo`) |

## Error Codes
| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | bad answer | `example_profile` or `segment` names nothing on offer, or is not a string; `load-example-set` with no stored answer |
| 403 | not an admin | any setup endpoint called by a non-admin; a `segment` answer from a user outside `admin` and `administration-managers` |
| 404 | unknown action | `actionId` not in the table |
| 500 | import failed | OpenRegister missing or the import threw; the message says which |

## Versioning
`setup.version` and `SetupController::SETUP_VERSION` move from 1 to 2 together. The descriptor carries `info.version`; bump it when a set's content changes (the import is idempotent by uuid, so a changed set re-imports only new objects).

## Breaking Change Policy
The legacy keys and actions above stay accepted. A change to the descriptor rules is a change to this contract and to `ExampleSetDescriptorContractTest` in the same PR.

## SLA
Listing reads a handful of files. Loading a set of several thousand objects is one synchronous request; the e2e suite already allows the generated set's 40 to 50 seconds.
