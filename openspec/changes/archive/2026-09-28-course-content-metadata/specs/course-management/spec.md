# Course management

## ADDED Requirements

### Requirement: Courses and lessons carry sharing metadata aligned to NL-LOM
`Course` and `Lesson` MUST declare four optional properties: `license` (one of `CC0-1.0`, `CC-BY-4.0`, `CC-BY-SA-4.0`, `CC-BY-NC-4.0`, `CC-BY-NC-SA-4.0`, `CC-BY-ND-4.0`, `CC-BY-NC-ND-4.0`, `all-rights-reserved`), `author` (the name to credit), `subject` (the subject name as the Onderwijsbegrippenkader (OBK) gives it) and `educationalLevels` (an array of `po`, `so`, `vmbo`, `havo`, `vwo`, `mbo-1`, `mbo-2`, `mbo-3`, `mbo-4`, `hbo`, `wo`, `adult-education`, `professional-training`). None of them MAY be required, and `license` MUST have no default.

#### Scenario: A teacher marks a course as openly licensed
- **GIVEN** a `Course` in draft
- **WHEN** the teacher sets `license: "CC-BY-SA-4.0"`, `author: "Sectie wiskunde, OSG De Vaart"`, `subject: "Rekenen/wiskunde"` and `educationalLevels: ["havo", "vwo"]`
- **THEN** the course validates and all four values persist

#### Scenario: An existing course without metadata stays valid
- **GIVEN** a `Course` stored before this change
- **WHEN** it is read and saved again
- **THEN** it validates with the four properties absent

#### Scenario: A lesson without a licence falls back to its course
- **GIVEN** a `Lesson` with no `license` in a `Course` with `license: "CC-BY-4.0"`
- **WHEN** a consumer resolves the lesson's licence
- **THEN** it uses `CC-BY-4.0`, as the lesson `license` description states

### Requirement: Licence and level values have translated labels
`license` and `educationalLevels.items` MUST declare `x-enum-labels` for every value, and every label MUST have an English key and a Dutch value in the catalogue.

#### Scenario: A Dutch teacher picks a licence
- **GIVEN** a Dutch-language user opens the course form
- **WHEN** the licence field renders
- **THEN** `all-rights-reserved` shows as "Alle rechten voorbehouden"
