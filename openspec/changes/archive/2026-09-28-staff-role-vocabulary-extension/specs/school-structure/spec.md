# School Structure

## ADDED Requirements

### Requirement: Staff roles name the counsellor and exam functions a school staffs
`Staff.roles` MUST accept, in addition to `teacher`, `mentor`, `coordinator`, `teaching-assistant`, `support-staff`, `administrator` and `other`, the values `career-counsellor` (decaan, loopbaanbegeleider), `study-adviser` (studieadviseur), `remedial-teacher`, `care-coordinator` (intern begeleider, zorgcoördinator), `exam-secretary` (examensecretaris), `placement-coordinator` (stagecoördinator) and `confidential-counsellor` (vertrouwenspersoon). The seven original values MUST keep their position at the start of the enum, so no stored value changes meaning.

#### Scenario: A school records its exam secretary and its decaan
- **GIVEN** the `Staff` schema is registered
- **WHEN** a `Staff` object is created with `roles: ["teacher", "career-counsellor", "exam-secretary"]`
- **THEN** the object validates and all three tags persist

#### Scenario: An existing Staff row stays valid
- **GIVEN** a `Staff` row stored before this change with `roles: ["teacher", "mentor"]`
- **WHEN** it is read and saved again
- **THEN** it validates unchanged

### Requirement: Every Staff role has a readable, translated label
`Staff.roles.items` MUST declare an `x-enum-labels` map with an English label for every enum value, and every label MUST have a key in `l10n/en.json` and a Dutch value in `l10n/nl.json`.

#### Scenario: The roles picker shows labels, not codes
- **GIVEN** a Dutch-language user opens the `Staff` form
- **WHEN** the roles field renders its options
- **THEN** it shows "Examensecretaris" for `exam-secretary` and "Vertrouwenspersoon" for `confidential-counsellor`

### Requirement: A Staff role tag grants no access
A `Staff.roles` value MUST be descriptive metadata only. No schema `authorization` block and no manifest `visibleIf` MAY name a `Staff.roles` value that is not also a declared security group or a `DashboardRoleService` role, and the `roles` property description MUST state that a tag grants no access.

#### Scenario: Tagging someone confidential counsellor does not open confidential notes
- **GIVEN** a `Staff` object tagged `confidential-counsellor` whose Nextcloud user is in no confidential group
- **WHEN** that user reads a schema whose `authorization.read` is limited to a confidential group
- **THEN** the tag has no effect on the result; only group membership decides
