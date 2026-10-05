# Proposal: simple-structure-profile

## Why

learniq's menu grew with the app. The manifest declares 163 menu nodes over 350 pages. After the layout step the built menu shows 108 entries: 100 in the main list (25 at the top level), 4 in the footer and 4 in settings. A teacher sees 57 of them, a coordinator 72.

The Zuiddrecht design asks for a menu that shows the work of one role: about ten entries in three groups, with everything else one level down and set-up in settings.

## What changes

learniq builds two structures from the same manifest.

- **Simple** is the new default. Each role gets at most ten main entries under three captions: Home, Teaching and Learners. A Nextcloud administrator gets twelve.
- **Full** is the menu as it was. An administrator brings it back with one setting, `menu_structure`, on the admin settings page.

No page is removed and no route changes. Both structures hold the same 350 pages.

### The menu follows the role

learniq already knows a user's role: `user.primaryRole`, resolved on the server by `DashboardRoleService` from the Nextcloud groups, and provided to the page as initial state. The simple menu uses that signal and nothing new. A gate written in the simple profile may only narrow the gate the manifest already has for that page. A test builds both menus for every role and fails when the simple one shows a role a page the full one does not.

| role | main entries |
| --- | --- |
| teacher (`instructor`) | Dashboard, My learning, Groups, My timetable, Lessons and assignments, Marking, Learners, Attendance, Progress, Care and dossier |
| care coordinator (`coordinator`) | the same without Marking, with Timetables |
| administration (`administration-manager`) | the same without Marking and Care, with Timetables and People |
| learner | Dashboard, My learning, My timetable, Course catalogue, My learning record, Check in, My work groups, My evaluations, Optional lessons, Pick electives |
| guardian | Dashboard, My learning, My timetable, My learning record, Pick electives, Book a conversation |

### Where the rest goes

- **Settings.** The set-up lists (templates, periods, rooms, screens, locations, schools, staff, fees, rounds) and the import, export and exchange tools move to the settings foldout. Each keeps its own gate.
- **Header links.** Groups, Marking, Learners, Attendance and Care and dossier each gain links to the lists that used to sit next to them.
- **Report cards.** Three readings become cards on the Reports page.
- **Existing landing pages.** Progress and Compliance already card their retired entries. Lessons and assignments and People already list theirs.

### What the simple menu does not link yet

Some entries of the full menu have no door in the simple one. They stay routable and the full menu still offers them. They are named per role in `KNOWN_UNLINKED` in `tests/unit-js/structureProfile.test.mjs`, and that list is exact in both directions.

They fall in four groups, and each needs a hub page this change deliberately leaves out:

1. **Planning**: optional lessons, exam sittings, invigilation requests and availability, exam accommodations, standby hours, teaching activities, teacher availability, timetable conflicts, the school calendar, the conference schedule board, and Timetables for teachers and learners.
2. **Admissions**: the review board and school advice.
3. **My learning, for staff**: the course catalogue and my learning record for teachers, coordinators and administration. Check in, work groups, evaluations and electives for an administrator.
4. **Dashboard views**: the Administration, Teaching and Learner views of the dashboard, and the Mentor, IB and Director entries that open the teaching view. The dashboard at `/` shows a user's default view only.

Also unlinked: Subject choices, People for teachers and coordinators, Book a conversation for a learner, and absence reports for a compliance officer.

### What the design names and learniq does not have

- **Berichten.** learniq has no messages page. The entry is left out.
- **Mijn groepen.** The Groups entry opens the Cohorts list, which shows every group the user may read, not only their own.
- **Mentor, examencommissie, directie.** These are not roles the server resolves. The resolver knows admin, compliance officer, HR, administration manager, team lead, coordinator, instructor, confidential counsellor, guardian and learner. The simple menu follows those.

## What the library cannot express

- Lifting a child entry to the top level without a relocation step. A relocation step drops the captions. The profile adds five top-level twins instead (ids ending in `Simple`).
- Appending cards to a `nav-card-grid` widget: the cards sit inside a widget's content, below what a page overlay can reach.
- A role gate on a header link.
- Active state that respects `query`.

## Impact

Every instance flips to the simple menu on update. An administrator switches back under Administration settings, Learniq, Menu structure, or with `occ config:app:set learniq menu_structure --value=full`.

The e2e CI instance is seeded on `full`, so the existing suite keeps walking the full menu.
