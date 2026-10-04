# Lane log: f-product (learniq follow-ups, 2026-09-28)

Clone /home/rubenlinde/memcap-work/lq-lanes/lq-school. Heavy-run logs in .tmp/ (gitignored). Strict runs with HOME=.tmp/home (per-lane pdepend cache).

## 1. segment-tidy (D34): DONE
- Branch fix/segment-tidy from origin/development, commit da3c4f2e, pushed. PR https://github.com/ConductionNL/learniq/pull/1192
- strict exit 0; lint 0; format 0; js-unit 1 inherited fail (openregisterSchemaRefs); gates exit 3 (53 runner ESM, 112, 113 inherited).

## 2. example-set-regulation-dedupe: DONE
- Branch fix/example-set-regulation-dedupe, commit f2777df0. PR https://github.com/ConductionNL/learniq/pull/1193
- strict exit 0; lint 0; gates exit 2 (112, 113 inherited). Conflicts textually with #1192 on the SeedProfileService constructor.

## 3. training-set-qti-2-1: DONE
- Branch fix/training-set-qti-2-1, commit 760e7a8f. PR https://github.com/ConductionNL/learniq/pull/1194. strict 0; gates exit 2 (112, 113 inherited).

## 4. timetable-connection-and-import-screen: DONE
- Branch feat/timetable-connection-and-import-screen, commits cc3f0dbc, 5efb73ae. PR https://github.com/ConductionNL/learniq/pull/1196
- strict 0; lint 0; format 0; js-unit 1 inherited; gates exit 2 (112, 113 inherited).

## CI read (once)
- #1192, #1193: PHPUnit red = failOnRisky on 4 inherited course-sharing/course-store risky tests (CourseSharingControllerTest, CourseShareExportServiceTest x2, CourseMetadataFilterTest); all tests pass; Coverage Baseline Protection passes. Hydra Gates full-tree inherited. #1194 PHPUnit pending at read; #1196 not read (just opened). No NEW red.
- LANE DONE.
