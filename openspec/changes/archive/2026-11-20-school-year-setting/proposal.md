# Proposal: school-year-setting

## Why

Learniq counted the school year from 1 August in at least five places (contact hours, standby validity, hour plans,
attendance summaries, the subject choice placeholder). That is a Dutch convention written into code; a school that
starts its year on another day got wrong year bounds. Ruben accepted (LQ-ABSTRACT question 16) that the start is a
school calendar setting.

## What changes

- An app setting `school_year_start` (`MM-DD`, default `08-01`) read by one `SchoolYear` service.
- The contact hours calendar and reader, and the standby slot validity default, read the school year from it.
- The admin page offers the setting in its school calendar section (LQ5-ADMIN).
