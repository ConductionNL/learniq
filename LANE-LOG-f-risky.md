# LANE-LOG f-risky (learniq)

- Branch `fix/course-store-tests-coverage-metadata` from origin/development (cc0913d2 era), commit eb5588e2.
- Source of the list: 7 learniq PR PHPUnit jobs (#1216, #1213, #1208, #1203, #1200, #1196, #1195), all the SAME 4 risky tests in 3 classes; learniq phpunit.xml has failOnRisky="true", so these 4 are the job's exit 1.
- StoreControllerTest and CourseStorePublisherTest named in the brief are NOT risky in any of those jobs (StoreControllerTest already carries @uses); untouched.
- No coverage driver on this box (php -m: no pcov/xdebug): proof has to come from the PR's CI run.
- check:strict exit 0 (ALL CHECKS PASSED). Pushed eb5588e2, PR https://github.com/ConductionNL/learniq/pull/1230. CI read once: PHPUnit PASS, 2177 tests, 0 risky (was 4). Hydra Gates red on inherited full-repo findings (gate-3, 25, 49, 55; none about test docblocks), Quality Report red as a consequence.
