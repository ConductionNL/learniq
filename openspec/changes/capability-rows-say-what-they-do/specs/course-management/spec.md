## ADDED Requirements

### Requirement: A designer attaches a new file to a lesson as a Material

In a media block of the lesson composer, an instructional designer MUST be able to pick a file from their Nextcloud files. The system MUST then create a `Material` linked to the lesson, with the file's name as title, a kind that fits the file type and the file path as `fileRef`, and MUST select that Material in the block.

#### Scenario: A designer adds a video to a lesson

- **GIVEN** an instructional designer editing a lesson with a media block
- **WHEN** they choose "Upload a new file…" and pick `intro.mp4` from their files
- **THEN** a `Material` with title `intro.mp4`, kind `video` and that `fileRef` exists for the lesson
- **AND** the media block now points at that Material

#### Scenario: The designer closes the picker

- **GIVEN** the file picker is open from a media block
- **WHEN** the designer closes it without choosing a file
- **THEN** no Material is created and no error is shown

### Requirement: An import report marks a reduced resource as degraded with its reason

When the importer keeps a resource only in reduced form, the report entry for it MUST have outcome `degraded`, name the object it became and give a reason that says what is missing. The report MUST count it in `resourcesDegraded` and MUST resolve to `partial`.

#### Scenario: An LTI link arrives without a deployment

- **GIVEN** a Common Cartridge package with a `basiclti` resource
- **WHEN** the package is imported
- **THEN** the report entry for that resource has outcome `degraded` and target type `lti-tool-placement`
- **AND** its reason says an admin must bind a deployment before the tool can be launched
- **AND** the report counts one degraded resource and resolves to `partial`
