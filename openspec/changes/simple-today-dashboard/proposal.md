# Proposal: simple-today-dashboard

## Why

The simple structure (simple-structure-profile) gives each role a short menu. Its first entry still opens the role dashboard: lists to manage, with no view of the day. The Zuiddrecht design opens on Vandaag: what needs you first, your lessons, and a few counts that each open the list they count.

## What changes

In the simple structure the page at `/` becomes the Today dashboard for the four roles that teach or run teaching: teacher, coordinator, administration manager and administrator. The menu entry reads Today.

For every other role, and for everybody in the full structure, the page stays the role dashboard it was.

Today shows, top to bottom:

1. A greeting with the date.
2. **First today**: a card that appears only when attendance flags are still open. Its button opens exactly those flags.
3. Four counts: Lessons today, Lessons this week, Assignments due, Unexcused today. Each opens the list it counts, with the same filter.
4. **Lessons this week**: the lessons per working day. A lesson opens its page.
5. **Other dashboards**: the Teaching, Learner and Administration dashboards, one card each, for whoever may open them.

The header holds one button: Today's register.

No page is added. The page keeps its id and its address. It is built from library widgets only, so no custom page is added either.

## How

The profile file gains one overlay of a new kind on the `Dashboard` page. `src/utils/structureProfile.js` learns two overlay keys:

- `when`: a predicate on the manifest runtime. The overlay applies only for a signed-in user it passes for. It is judged by the library's own evaluator, which `src/main.js` hands in. Without an evaluator the overlay is skipped and the page stays what it was.
- `page`: replaces the page's `type`, `title` or `component`. A `null` takes the key out.

## What the design shows and this does not

learniq has no data or no page for these, so they are left out and named here:

- **Na te kijken** and the marking progress bars: submissions are listed per assignment only.
- **Aanwezig vandaag** as a percentage: there is no count of expected pupils to divide by. The count of unexcused absences marked today is shown instead.
- **Signalen in mijn groepen** by pupil name: the open attendance flags are counted on the First today card and opened as a list.
- **Berichten van ouders**: learniq has no messages.
- The filled-in state per lesson and the Aanwezigheid button per lesson. Today's register is the header button.
- The Docent / Mentor switch: mentor is not a role the server resolves.
- "My" lessons: the counts and the week show the lessons the signed-in user may read. For a teacher that is their own groups, where the register's access rules say so. Nothing here filters on the user.

## Impact

Teachers, coordinators, administration managers and administrators land on Today after the update, in the simple structure. The role dashboards stay at `/dashboards/teaching`, `/dashboards/my-learning` and `/dashboards/admin`, one card away. This also links the dashboard views that simple-structure-profile listed as unlinked for these four roles.
