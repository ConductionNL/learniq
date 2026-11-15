# Design: course-content-metadata

## Architecture Overview
`Course` and `Lesson` are declarative OpenRegister schemas in `lib/Settings/learniq_register.json`. The change appends four optional properties to each. Forms render them through `fieldsFromSchema()` (`@conduction/nextcloud-vue`), which reads `x-enum-labels` on a property and on array `items`.

## Nextcloud Integration
- Controllers: none.
- Services: none. `CoursePackageExportService::exportScholiqJson()` serialises the whole course and lesson objects, so the fields travel in that export without code.
- Mappers/Entities: none.
- Events/Hooks: none.

## Decisions

### D1: A licence list, not free text
The consent gate and the store read `license` to decide whether something may leave the school. A free string (`Material.license` today) cannot be checked. The list is the Creative Commons 4.0 family Wikiwijs uses, plus CC0 and `all-rights-reserved` so a school can say "not shareable" explicitly. Codes are SPDX identifiers, so they stay meaningful outside learniq. Alternative considered: free SPDX string. Rejected because it cannot be validated.

### D2: No default licence
Choosing a licence is a legal act by the rights holder (usually the school as employer). A default would make every course "openly licensed" without anyone deciding. So the field starts empty, and the consent gate treats empty as "not shareable".

### D3: `educationalLevels` next to `level`, not instead of it
`Course.level` is the sector (`po`, `vo`, `mbo`, `hbo`, `wo`, `corporate`) and other code reads it. NL-LOM levels are finer (VMBO, HAVO, VWO, MBO level 1 to 4) and a lesson often fits several, so a separate array. The values follow the NL-LOM onderwijsniveau top concepts, with `professional-training` for the company and training-institute segments (D21).

### D4: `subject` as the OBK name, no picker yet
The OBK is the recommended vocabulary for NL-LOM subject. Learniq has no copy of it. A typed name, with the description pointing at the OBK spelling, is useful now and upgradeable later by adding a concept id next to it.

### D5: A lesson licence is optional and falls back to the course
Most lessons share their course's licence. The description says so; the consent gate applies the fallback.

### Declarative-vs-imperative decision
| Behaviour | Path | Rationale |
|---|---|---|
| Four metadata fields | Declarative, register properties | Plain data; no lifecycle, aggregation or derived value. |

## Security Considerations
No security impact: optional descriptive fields on schemas whose `authorization` blocks are unchanged.

## File Structure
```
lib/Settings/learniq_register.json        Course + Lesson properties, versions, info.version
lib/Settings/learniq_mock_register.json   demo values on one Course and one Lesson row
l10n/en.json, l10n/nl.json (+ .js)        labels
tests/Unit/Settings/CourseContentMetadataRegisterTest.php   new
```

## Seed Data
`Course` and `Lesson` have no register seed rows and this change adds none (the segment example-data lanes own course seeds). In the demo register, the first `Course` row gets `license: "CC-BY-SA-4.0"`, `author: "Voorbeeldschool, sectie Nederlands"`, `subject: "Nederlandse taal"`, `educationalLevels: ["havo", "vwo"]`, and the first `Lesson` row gets `subject: "Nederlandse taal"` and `educationalLevels: ["havo"]`, so the fields show on a fresh demo install.

## Trade-offs
An enum can go stale when Creative Commons publishes a 5.0 family; adding values is additive. A free `subject` fragments search until a vocabulary link lands.
