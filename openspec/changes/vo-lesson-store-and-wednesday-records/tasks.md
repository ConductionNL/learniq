# Tasks: vo-lesson-store-and-wednesday-records

- [x] Add `add_lesson_and_store_boards()` to `scripts/example-sets/vo.py`, appended after every other step, with no random draw
- [x] Append `lesson`, `material` and `shared-course-package` to the vo schema order
- [x] Rewrite 3HV-B's Wednesday in `H4B_WEEK` to LqLlVandaag's lessons, keeping its five uuids
- [x] Name the Biologie teacher Anouk Smit and declare her account in `lib/Settings/portals/vo.json`
- [x] Regenerate `lib/Settings/profiles/vo.json`; every existing uuid stays
- [x] Assert the records in `SecondarySchoolLessonAndStoreRecordsTest`
- [x] Read the sole school's name for the HR Today subtitle (`subtitleOfSchoolByRole`, `src/utils/schoolName.js`), with `tests/unit-js/schoolName.test.mjs`
