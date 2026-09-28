# Course management: shared Word reader delta

## ADDED Requirements

### Requirement: A Word file is read by OpenRegister's document reader when there is one

When OpenRegister provides `DocumentExtractor`, a confirmed Word file MUST be read through it into the lesson structure: a section per heading, paragraph, list and table blocks as paragraph text, pictures with their bytes within the existing limits, and a note when the read was cut off. When OpenRegister has no `DocumentExtractor`, or it fails or reads nothing, learniq's own Word reader MUST be used as before.

#### Scenario: OpenRegister's reader is used when present
@e2e exclude Server-side read with no UI step of its own; pinned by tests/Unit/Service/LessonOnboarding/DocumentLessonReaderTest.php::testTheExtractorPrefersOpenRegistersReader.
- **GIVEN** an OpenRegister with `DocumentExtractor`
- **WHEN** a teacher confirms a Word file
- **THEN** the lesson draft comes from `DocumentExtractor`'s sections
- **AND** learniq's own Word reader does not run

#### Scenario: Learniq's own reader is the fallback
@e2e exclude Server-side read with no UI step of its own; pinned by tests/Unit/Service/LessonOnboarding/DocumentLessonReaderTest.php::testTheExtractorFallsBackToDocxLessonReader.
- **GIVEN** an OpenRegister without `DocumentExtractor`
- **WHEN** a teacher confirms a Word file
- **THEN** `DocxLessonReader` reads it as before
