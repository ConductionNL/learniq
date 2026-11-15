# Migration: office-file-lesson-onboarding

## Schema changes
- New schema `LessonOnboardingFile` (slug `lesson-onboarding-file`, version 0.1.0) in `lib/Settings/learniq_register.json`.
- `Lesson.blocks.items.properties.type.enum` gains `teacherNote`; `Lesson.version` 0.3.0 to 0.4.0.
- Register `info.version` minor bump.

## How it lands
The register descriptor is imported by the existing install and upgrade repair step
(`ConfigurationService::importFromApp()`); no Nextcloud migration class and no table. Existing lessons keep
validating: the enum only widens.

## Data
None to move. No existing object changes shape.

## Rollback
Revert the descriptor. A `LessonOnboardingFile` row left behind is inert. A lesson that holds a `teacherNote`
block would fail validation on its next save after a rollback; remove the block or convert it to `richText`
first.
