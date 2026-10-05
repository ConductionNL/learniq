# Lane learniq (Zuiddrecht build): state

Clone: ~/memcap-work/zuiddrecht/build/learniq (ConductionNL/learniq, app id `learniq`). Lane logs under .tmp/ (untracked, excluded via .git/info/exclude).
Brief: ../brief.md. Pattern: ../PATTERN-simple-structure.md.

## Measured on development fff3c823 (05 Oct)
- manifest declares 163 menu nodes (50 top-level, 113 children) and 350 pages (135 index, 128 detail, 80 custom, 3 dashboard, 4 other)
- BUILT full menu: 108 entries (25 top-level; 100 main, 4 footer, 4 settings). 42 are retired to landing cards, 13 group shells dissolve.
- per role in the full menu: admin 99, coordinator 72, administration-manager 72, instructor 57, team-lead 25, hr 16, learner 15, compliance-officer 13, guardian 9, confidential-counsellor 9
- role signal EXISTS: `user.primaryRole` from DashboardRoleService (NC groups), initial state `primaryRole`. No mentor/examencommissie/directie roles.
- nextcloud-vue already ^2.60.0 (installed 2.60.0). No vitest: JS tests are `node --test tests/unit-js/*.test.mjs`, and `test:js-unit` is NOT run by CI. CI frontend checks: check:specs, format, check:l10n-js, check:schema-l10n.
- learniq already has a typed hub pattern: `type: dashboard` + `nav-card-grid` widget (ProgressLanding, ComplianceLanding). No new page added in PR 1 (coordinator said leave the hub out).
- the dashboard's "in-page switcher" for role views is only a comment: LearniqDashboards.vue has no switcher, so role views are unlinked in the simple menu.

## PR 1 simple-structure-profile: branch feature/simple-structure-profile (wip pushed)
- done: structureProfile.js, menu-layout.simple.json, main.js, MenuStructure.php, SettingsService key, PageController initial state, admin section, l10n en+nl, guard test wired into check:specs, PHP test, e2e spec, ci-seed full, openspec change
- todo: composer install -> phpunit single test, diff checks; then ONCE: check:strict, npm run lint, format, check:specs, check:l10n-js, check:schema-l10n, build (flock + systemd-run MemoryMax=4G); open PR --base development

## PR 2 Vandaag dashboard: not started (stack on PR 1)
