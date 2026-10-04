# MERGE-LOG learniq round 1 (merge lane, 2026-09-27)

| PR | branch | conflicts | resolved | checks (exit) | merge sha |
|---|---|---|---|---|---|
| 907 | fix/registry-component-fix | no | - | check:register 0, check:manifest 0 | 955c1f56 |
| 909 | fix/session-roster-notifications | no (register 0.22.0 > dev 0.21.0) | - | check:register 0 | PREPARED, pushed ec4a51fc, NOT MERGED (admin merge refused by classifier; not retried) |

RESUMED 2026-09-27 on coordinator relay: admin merge NOT retried (a relayed agent message is not user consent, and the classifier refusal said only a user permission rule clears it). Every remaining branch got development merged in and was pushed; none merged. Dev tip unchanged at 955c1f56 (register 0.21.0), so all branches are current against it.
| 915 | fix/cohort-group-provisioning | no | - | check:register 0 | PREPARED, pushed 808e7c37, NOT MERGED (admin merge not attempted: classifier refusal stands) |
| 918 | fix/credential-renewal-listener | yes: SchedulingListenerRegistrar.php (use imports) | kept both imports (AssessmentAttemptGateListener from dev + CredentialRenewalListener) | php -l 0, check:register 0, phpunit --filter both listeners 16 tests OK (exit 1 only from the "no coverage driver" warning) | PREPARED, pushed ce8925a8, NOT MERGED |
| 926 | fix/attendance-threshold-calculation | no | - | check:register 0 | PREPARED, pushed 823e5a03, NOT MERGED |
| 905 | feat/school-and-location-records | yes: l10n/en.json, nl.json, en.js, nl.js | JSON union (dev + branch keys, 0 scalar clashes), .js regenerated with l10n:build | JSON parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 613e30d7, NOT MERGED |
| 911 | feat/enrolment-statutory-fields | yes: l10n en/nl json+js | JSON union, 0 scalar clashes, .js regenerated | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 353bd8e1, NOT MERGED |
| 929 | feat/subject-and-teacher-assignment | yes: l10n en/nl json+js | JSON union, .js regenerated; check:schema-l10n went red (2414 > dev baseline 2413: branch strings "Role","Days" had no key, hidden by the branch baseline 2416) so added en "Role","Days" + nl "Days"="Dagen" ("Role"="Rol" already there) | parse 0/0, check:l10n-js 0, check:schema-l10n 1 then 0, check:register 0 | PREPARED, pushed 919fbc81, NOT MERGED |
| 932 | feat/cohort-group-page-polish | yes: l10n en/nl json+js | JSON union, .js regenerated | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 3ef51672, NOT MERGED |
| 934 | feat/school-year-shape | yes: l10n en/nl json+js | JSON union, .js regenerated | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed dfb2687e, NOT MERGED |
| 935 | feat/segment-feature-flags | yes: l10n en/nl json+js | JSON union, .js regenerated | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 9b595375, NOT MERGED |
| 904 | feat/lvs-import-contract | yes: l10n en/nl json+js | JSON union, .js regenerated; branch adds schemas without a register bump (0.21.0 = dev) so bumped info.version to 0.21.1 (no test asserts it) | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 52797738, NOT MERGED |
| 908 | feat/learner-record-extras | yes: l10n en/nl json+js | JSON union, .js regenerated; register changed with no bump, info.version 0.21.0 -> 0.21.1 | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 8ff175e2, NOT MERGED |
| 910 | feat/report-card-templates | yes: l10n en/nl json+js | JSON union, .js regenerated (register 0.22.0 > 0.21.0) | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 82e50eb4, NOT MERGED |
| 912 | feat/privacy-governance-surfaces | yes: l10n en/nl json+js | JSON union, .js regenerated (register 0.22.0) | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 61cc61e5, NOT MERGED |
| 913 | feat/oso-inbound-contract | yes: l10n en/nl json+js | JSON union, .js regenerated; register changed with no bump, 0.21.0 -> 0.21.1 | parse 0/0, check:l10n-js 0, check:schema-l10n 0, check:register 0 | PREPARED, pushed 5ba5eb8e, NOT MERGED |
| 914 | feat/uwlr-eduv-basispoort-contract | no (clean) | register changed with no bump, 0.21.0 -> 0.21.1 | check:register 0; check:schema-l10n 1 (2415 > dev baseline 2413): 2 PR-rewritten connection-name descriptions (learniq_register `connection` descriptions naming uwlr/edu-v/basispoort/entree-content) have no en/nl key; branch alone passed on its old baseline 2416. NOT fixed (needs real nl translation of long prose, not mechanical). Pushed before I noticed the red. | PREPARED, pushed 7b8ac423, NOT MERGED, schema-l10n RED (PR-introduced) |
| 916 | feat/rbac-scope-kinds-extension | no (clean) | - (register 0.23.0 > 0.21.0) | check:register 0, check:l10n-js 0, check:schema-l10n 1: PR-added careTeamUserIds title+description have no en/nl key (branch hid it under old baseline 2416); not fixed, needs nl prose | PREPARED, pushed 6417cc3d, NOT MERGED, schema-l10n RED (PR-introduced) |
| 917 | feat/payment-request-ux | yes: l10n en/nl json+js | JSON union, .js regenerated (register 0.24.0) | parse 0/0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed ee9730d6, NOT MERGED |
| 919 | feat/global-search | no (clean) | - (register untouched) | check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed 357210a0, NOT MERGED |
| 920 | feat/assessment-completeness | yes: l10n en/nl json+js | JSON union, .js regenerated; register changed with no bump, 0.21.0 -> 0.21.1 | parse 0/0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed d3c99b4e, NOT MERGED |
| 921 | feat/lesson-player-runtime | no (clean) | - (register untouched) | check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed 38ffd787, NOT MERGED |
| 922 | feat/statutory-field-completeness | yes: l10n/en.json, en.js | JSON union, .js regenerated; register changed with no bump, 0.21.0 -> 0.21.1 | parse 0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed c39a778c, NOT MERGED |
| 924 | feat/care-and-support-index | yes: l10n en/nl json+js | JSON union, .js regenerated (register 0.22.0) | parse 0/0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed dc4b84ae, NOT MERGED |
| 925 | feat/entree-surfconext-sso-contract | yes: l10n en/nl json+js | JSON union, .js regenerated; register changed with no bump, 0.21.0 -> 0.21.1 | parse 0/0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed 77a44511, NOT MERGED |
| 927 | feat/funding-and-teldatum-checks | yes: l10n en/nl json+js | JSON union, .js regenerated (register 0.22.0) | parse 0/0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed 358710aa, NOT MERGED |
| 928 | feat/portal-contribution-guardian-audiences | yes: l10n en/nl json+js | JSON union, .js regenerated (register 0.22.0) | parse 0/0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed 0b8f4002, NOT MERGED |
| 930 | feat/role-dashboards | yes: l10n en/nl json+js | JSON union, .js regenerated (register untouched; src/registry.js auto-merged clean) | parse 0/0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed 1e3a98a7, NOT MERGED |
| 931 | feat/data-mapping-profile-presets | no (clean) | register changed with no bump, 0.21.0 -> 0.21.1 | check:register 0, check:l10n-js 0, check:schema-l10n 1: PR-rewritten connection `target.description` strings (adds migration-import) have no en/nl key, hidden on the branch by old baseline 2416; not fixed (nl prose). NB 914 rewrites the SAME two descriptions, so 914 and 931 will also collide with each other | PREPARED, pushed 2d561814, NOT MERGED, schema-l10n RED (PR-introduced) |
| 933 | feat/trend-and-export-reporting | yes: l10n en/nl json+js | JSON union, .js regenerated (register 0.22.0) | parse 0/0, check:register 0, check:l10n-js 0, check:schema-l10n 1: PR-added `kind.description` (dle/leerrendement discriminator) has no en/nl key; not fixed (nl prose) | PREPARED, pushed f8180316, NOT MERGED, schema-l10n RED (PR-introduced) |
| 936 | feat/po-schooladvies-flow | yes: l10n en/nl json+js, l10n/.schema-l10n-baseline.json | JSON union, .js regenerated; baseline kept at branch 2409 (stricter than dev 2413; merged tree has 2408) (register 0.22.0) | parse 0/0, check:register 0, check:schema-l10n 0, check:l10n-js 0 | PREPARED, pushed b8d21fe7, NOT MERGED |
10:02 | 909 | fix/session-roster-notifications | MERGED a28be1fa | conflicts:  
10:02 | 915 | fix/cohort-group-provisioning | MERGED 90d29044 | conflicts:  
10:06 | 926 | fix/attendance-threshold-calculation | MERGED ef621887 | conflicts: lib/Settings/learniq_register.json 
10:06 | 905 | feat/school-and-location-records | MERGED 17eb9019 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_register.json 
10:06 | 911 | feat/enrolment-statutory-fields | MERGED 16ff6350 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_register.json 
11:05 | 929 | feat/subject-and-teacher-assignment | MERGED 0251a06b | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json src/manifest.d/people.json 
11:05 | 932 | feat/cohort-group-page-polish | MERGED 3ed07a96 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_register.json 
11:05 | 934 | feat/school-year-shape | MERGED 56b2654d | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_register.json 
11:06 | 935 | feat/segment-feature-flags | MERGED ad254ec1 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
11:06 | 904 | feat/lvs-import-contract | MERGED 32b43a06 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
11:06 | 908 | feat/learner-record-extras | MERGED d39b6fe7 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
11:06 | 910 | feat/report-card-templates | MERGED 6318961d | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
11:06 | 912 | feat/privacy-governance-surfaces | MERGED 963416b9 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
11:07 | 913 | feat/oso-inbound-contract | MERGED 74de57f1 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
11:07 | 914 | feat/uwlr-eduv-basispoort-contract | MERGED f7535fe3 | conflicts: lib/Settings/learniq_register.json 
11:07 | 916 | feat/rbac-scope-kinds-extension | MERGED 6fdfdc86 | conflicts: lib/Settings/learniq_register.json 
11:07 | 917 | feat/payment-request-ux | MERGED e91c9c92 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_register.json 
11:07 | 919 | feat/global-search | MERGED e81e47d4 | conflicts:  
11:08 | 920 | feat/assessment-completeness | MERGED ea19b315 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_register.json 
11:19 | 921 | feat/lesson-player-runtime | MERGED b6686c07 | conflicts: src/views/LessonPlayer.vue (imports, both kept; eslint 0, 20 unit tests pass)
11:19 | 922 | feat/statutory-field-completeness | MERGED d755c142 | conflicts: l10n/en.js l10n/en.json lib/Settings/learniq_register.json 
11:19 | 924 | feat/care-and-support-index | MERGED 99e08734 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
11:19 | 925 | feat/entree-surfconext-sso-contract | MERGED 4fb797cb | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
11:22 | 927 | feat/funding-and-teldatum-checks | MERGED 3863163d | conflicts: DataExchangeRunGuard.php + test (both conditions and both test sets kept; 10 tests pass), register, l10n
11:22 | 928 | feat/portal-contribution-guardian-audiences | MERGED 788b711a | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_register.json 
11:22 | 930 | feat/role-dashboards | MERGED 94da0a31 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json 
11:22 | 931 | feat/data-mapping-profile-presets | MERGED 072dd694 | conflicts: lib/Settings/learniq_register.json 
11:22 | 933 | feat/trend-and-export-reporting | MERGED e062f913 | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_register.json 
11:23 | 936 | feat/po-schooladvies-flow | MERGED 4a6e9dcd | conflicts: l10n/en.js l10n/en.json l10n/nl.js l10n/nl.json lib/Settings/learniq_mock_register.json lib/Settings/learniq_register.json 
