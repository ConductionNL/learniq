# Design: simple-structure-profile

## One manifest, two layout files

`src/menu-layout.json` is the full profile and is not touched. `src/menu-layout.simple.json` is the simple one. `src/utils/structureProfile.js#buildProfiledManifest` hands the chosen file to the library's `buildManifest`. A file with no `menu` and no `pages` key builds exactly what `buildManifest` builds, which is how the full profile stays what it was.

The simple file holds the two standard keys (`removals`, `settingsSection`) and two profile keys:

- `menu`: entries merged before the manifest's menu. The first definition of a key wins, so `{ "id": "GroupProgress", "order": 46 }` keeps the manifest's label, icon, route and gate and takes this order. A `visibleIf` written here replaces the manifest's.
- `pages`: overlays by page id. Only `configAppend` is used: header links on five lists and three cards on the Reports page.

There is no `relocations` key. Any relocation object makes the library drop entries with no route, which is what a caption is.

## Decisions

1. **The role signal is the existing one.** `user.primaryRole` comes from `DashboardRoleService` and is already initial state. No new role, group or setting.
2. **A simple gate only narrows.** Menu visibility is not a permission, and the server decides what a user may read. But a menu that shows a role a page it never had invites a refusal. The test compares per role, per segment.
3. **Twins instead of relocations.** Learners, Attendance, Dossier notes, Pick electives and Book a conversation are children of groups. They get a top-level twin with a new id and the same route, icon and gate.
4. **Two new doors.** Cohorts and Submissions had pages and no menu entry. Groups opens for the roles that see Learning. Marking opens for teachers.
5. **Ten is the ceiling, twelve for an administrator.** An administrator holds every role's rights. Their menu is the teacher's plus Timetables and Compliance. The learner's own entries are not shown to an administrator.
6. **No hub page.** A hub would link what is unlinked today. It is left for the typed link-cards page. Until then the unlinked entries are counted and named in the test.
7. **The setting is read through the settings endpoint on the admin page.** The admin page is rendered by OpenRegister's generic settings class, which provides no initial state of learniq's own. The section reads `GET /api/settings` and writes `PUT /api/settings`, both admin-guarded.
8. **The page controller resolves the setting lazily.** Like every other value it provides, a failure degrades to the default, so the start page does not fail over a menu setting.

## Differences from the dossiq recipe

- No vitest in this repo. The guard is a `node --test` file, wired into `check:specs` as `check:structure-profile`, which CI runs.
- `MenuStructure` takes app config and has `current()`. The page controller asks the container for it.
- No initial state on the admin page (decision 7).
- The CI seed sets `full` through the settings endpoint, not `occ`: the seed script here talks HTTP only.
- Role-aware menus: the recipe's flat list becomes a per-role check.
