---
kind: config
depends_on: []
---

# Proposal: course-content-metadata

## Summary
A course or lesson that is meant to travel to another school needs to say who made it, under which licence, for which subject and for which level. `Course` has a sector `level` and free `tags`; `Lesson` has none of these; only `Material` has a free-text `license`. This change adds four NL-LOM aligned fields to both `Course` and `Lesson`: `license` (an open-licence list plus "all rights reserved"), `author` (the name to credit), `subject` (the Onderwijsbegrippenkader (OBK) subject name) and `educationalLevels` (NL-LOM education levels, several allowed). They are the metadata the sharing changes after this one read.

## Motivation
Round 2 recon E, section 1c, row "Licence metadata on shareable content": `Material.license` exists "per-attachment (Material) only; `Course` itself has `code`/`name`/`description`/`level`/`language`/`tags` and no `license`, `author`, `subject` (topic) or content-version field". Proposed-changes row `course-content-metadata`.

Section 3 names the standard: NL-LOM, the Dutch metadata profile for learning material (Edustandaard and Wikiwijs), with subject, level, language, author and licence among its recommended fields, and the OBK as the recommended vocabulary for subject and level. Wikiwijs publishes every arrangement under an open Creative Commons licence (CC BY 4.0 or CC BY-SA 4.0 named explicitly).

Assumption A10: "a shared package carries a licence, NL-LOM metadata and a 'may leave the school' check". Decision D22 routes sharing through OpenRegister's store plane; the store card fields named for that change (title, subject, level, goals covered, language, licence, author) need these four fields to exist.

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/learniq_register.json` (`Course` and `Lesson` properties, versions, `info.version`), `lib/Settings/learniq_mock_register.json` (demo values), `l10n/`, `tests/Unit/Settings/CourseContentMetadataRegisterTest.php` (new), `tests/Unit/Settings/CourseAuthoringRegisterTest.php` (exact version pin to a floor).

## Scope

### In Scope
- On `Course` and `Lesson`: `license` (enum: `CC0-1.0`, `CC-BY-4.0`, `CC-BY-SA-4.0`, `CC-BY-NC-4.0`, `CC-BY-NC-SA-4.0`, `CC-BY-ND-4.0`, `CC-BY-NC-ND-4.0`, `all-rights-reserved`), `author` (string), `subject` (string, OBK subject name), `educationalLevels` (array of NL-LOM levels: `po`, `so`, `vmbo`, `havo`, `vwo`, `mbo-1` to `mbo-4`, `hbo`, `wo`, `adult-education`, `professional-training`).
- Labels for every enum value, Dutch catalogue values.
- A lesson with no licence uses the course licence; the description says so.

### Out of Scope
- Checking these fields before export (next change, `lesson-sharing-consent-gate`).
- An OBK vocabulary picker or import. `subject` is a name typed by the teacher; linking it to an OBK concept id can follow once a vocabulary source exists (the SLO importer lane is the likely one).
- Changing `Course.level` (the sector) or `Material.license`.
- Writing these fields into the Common Cartridge `imsmanifest.xml` metadata block.

## Approach
Additive, optional properties in the register. No required field, no default licence: a school chooses. No PHP.

## New Dependencies
None.

## Impact
- Course and lesson forms gain four optional fields.
- The scholiq-native JSON export carries them automatically (it serialises the whole object). The JSON importer copies only name, order and content fields today (`LearniqJsonCourseImporter::importTree()`); carrying licence and author on import belongs to `lesson-sharing-via-store-plane`, where installing a shared package must keep the credit a CC BY licence requires.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: `subject` as free text fragments search
**Severity:** Medium. **Mitigation:** the description tells teachers to use the OBK name; a vocabulary link is named as a follow-up.

### Risk 2: Parallel lanes edit `Course` and `Lesson`
**Severity:** Low. **Mitigation:** the edit is four appended properties per schema; the orchestrator lands in series.

## Rollback Strategy
Revert the commit; stored values become unknown properties OpenRegister ignores on read.

## Open Questions
None.
