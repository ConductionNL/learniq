# Tasks: data-mapping-profile-presets

## 1. Schema: target catalogue descriptions

- **spec_ref**: `openspec/changes/data-mapping-profile-presets/specs/data-exchange/spec.md#requirement-migration-import-job-type-and-payload-mappings`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN `DataExchangeJob.target` and `DataMappingProfile.target`'s descriptions
  - WHEN this change lands
  - THEN both name `migration-import` as a valid connection
- [x] Implement
- [x] Test

## 2. Schema: TimeEdit rostering-import preset

- **spec_ref**: `openspec/changes/data-mapping-profile-presets/specs/data-exchange/spec.md#requirement-timeedit-joins-the-rostering-import-preset-family`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `DataMappingProfile` seed data
  - WHEN the `TimeEdit timetable import` seed is added
  - THEN it matches the Zermelo/Untis/Xedule seeds' shape (`target: timetable-import`, `sourceSchema:
    session`)
- [x] Implement
- [x] Test

## 3. Schema: migration-import presets (ParnasSys/ESIS/Magister/SOMtoday)

- **spec_ref**: `openspec/changes/data-mapping-profile-presets/specs/data-exchange/spec.md#requirement-migration-import-job-type-and-payload-mappings`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `DataMappingProfile` seed data
  - WHEN the four migration-import seeds are added
  - THEN each is `target: migration-import`, `direction: import`, `sourceSchema: learner-profile`, and
    carries `eckId`
- [x] Implement
- [x] Test

## 4. Register tests

- **spec_ref**: `openspec/changes/data-mapping-profile-presets/specs/data-exchange/spec.md#requirement-migration-import-job-type-and-payload-mappings`
- **files**: `tests/Unit/Settings/DataMappingProfilePresetsRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register JSON
  - WHEN it is parsed
  - THEN all five new seeds resolve with the field coverage above
- [x] Implement
- [x] Test
