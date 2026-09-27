# Contract: competency-year-scope

This change adds no endpoint. The contract is the data shape of two properties on an OpenRegister object that
another app writes. The same text lives at `/home/rubenlinde/memcap-work/lq-lanes/CONTRACT-competency-fields.md`
for the parallel lane.

## Consumers
- `integriq` (change `slo-kerndoelen-import`, lane r2-slo): writes `Competency` rows imported from
  opendata.slo.nl and sets `applicableYears` and `subjectId` on them.
- `learniq` itself (later changes `curriculum-coverage-rollup` and `curriculum-coverage-matrix-view`): reads the
  two properties to group coverage by year and subject.

## Endpoints

### `POST /index.php/apps/openregister/api/objects/learniq/competency` (existing OpenRegister object API)
**Auth**: Nextcloud session or app password; the caller needs create rights on `Competency`.

**Request (the two new properties, both optional):**
```json
{
  "frameworkId": "00000000-0000-0000-0000-000000000000",
  "parentId": null,
  "code": "K1",
  "title": "Mondelinge taalvaardigheid",
  "applicableYears": ["groep 5", "groep 6"],
  "subjectId": null,
  "tenant_id": "00000000-0000-0000-0000-000000000000"
}
```

**Response (201):** the stored object, with `applicableYears` defaulting to `[]` and `subjectId` to `null` when
omitted.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `applicableYears` is not an array of strings, has a duplicate, or holds an empty string or one over 64 characters; `subjectId` is not a UUID |
| 401  | No session or credentials |
| 403  | The caller has no create or update right on `Competency` |

## Property contract

| Property | JSON type | Required | Default | Constraint |
|---|---|---|---|---|
| `applicableYears` | array of string | no | `[]` | `uniqueItems: true`; items `minLength: 1`, `maxLength: 64` |
| `subjectId` | string or null | no | `null` | `format: uuid`, `$ref: "Course"` |

No enum on either property.

`applicableYears` labels, one scheme per framework:
- Year levels, canonical spelling lower case with one space and an arabic numeral: `groep 1` to `groep 8`
  (primary), `leerjaar 1` to `leerjaar 6` (secondary and MBO), `jaar 1` to `jaar 4` (HBO and WO).
- Or academic years in the `Cohort.academicYear` format: `YYYY` or `YYYY-YYYY`.
- `[]` means every year of the framework.

Read rule (not stored): an empty `applicableYears` inherits the nearest ancestor's non-empty value; a null
`subjectId` inherits the nearest ancestor's non-null value. An importer may set either on a domain node only.

## Error Codes
| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | Validation failed | A value breaks the property contract above |
| 401 | Unauthenticated | No session or credentials |
| 403 | Forbidden | No create or update right on `Competency` |

## Versioning
`Competency.version` moves from 0.1.0 to 0.2.0 (additive minor). Every existing object stays valid, because both
properties are optional with empty defaults. The names are frozen once this change merges.

## Breaking Change Policy
Renaming either property, adding an enum, or changing the `$ref` target is breaking. It needs a new change in
learniq that names integriq as an affected project, and the contract file is updated before either side merges.

## SLA
Same as the OpenRegister object API. The two properties add no query or computation at write time.
