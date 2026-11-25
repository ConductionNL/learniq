# Design: extra time and free invigilators on an exam sitting

## Context

At development `de2d7388`:

- `lib/Controller/ExamScheduleController.php:84` `overview(id)` and `:107` `availableInvigilators(id)`, both `#[NoAdminRequired]`, routes `appinfo/routes.php:346-347`. Reads run with the caller's OpenRegister access; an unreadable sitting answers 404.
- `overview` returns `{sitting, accommodations: [{learnerId, endsAt, extraTimePercent, separateRoom}], invigilators: {needed, confirmed: [uid], pending: [uid], open}}`. Accommodations are read at request time from `exam-accommodation` rows in an applying state, so a revoked one stops counting at once.
- `availableInvigilators` returns a list of user ids: availability covers the sitting, not already asked.
- `invigilator-assignment` (examSittingId, invigilatorId, lifecycle pending/confirmed/declined); `InvigilatorAssignmentCheck` validates a new request.
- `ExamSittingDetail` widgets: `exam-sitting-data` (data) and `exam-sitting-invigilators` (object-list on invigilator-assignment).
- Body sections: `config.bodyWidgets` entries resolved by `CnBodySections` against registry entries with `kind: 'section'` (precedent `AssignmentHandInStatus` on AssignmentDetail). They are not grid widgets, so the custom-widget ratchet does not count them.

## Screen

No board draws it. `v2/capabilities-learniq.md` lists exam-schedule as "andere rol (planner, coördinator)", and the canvas draws the teacher. The section follows the detail-page layout of `LqToetsNakijken` for its rows (name left, figures right) and the house empty-state style. Labels: "Extra time and invigilators" / "Extra tijd en surveillanten", "Ends at" / "Eindigt om", "Separate room" / "Aparte ruimte", "Free for this sitting" / "Vrij voor deze zitting", "Ask" / "Vragen".

## Decisions

### D1: A section, not a grid widget

The section fetches two app routes; a grid widget type that calls an arbitrary route does not exist in nextcloud-vue and is what the ratchet discourages. A body section is the house precedent for exactly this.

### D2: "Ask" writes through OpenRegister

The button creates an `invigilator-assignment` with `examSittingId` and `invigilatorId` through the objects API (ADR-022), so `InvigilatorAssignmentCheck` and the notification to the colleague run as they do for a request made on the requests page. No new route.

### D3: Names from the people the caller may read

Learner names come from `learner-profile.fullName`, invigilator names from Nextcloud's user display name. When the caller may not read a profile the row shows "A learner" rather than a uuid.

### D4: Refresh after a change

After "Ask" the section reloads both routes and the Invigilators list, so the counts and the free list agree with the request just made.
