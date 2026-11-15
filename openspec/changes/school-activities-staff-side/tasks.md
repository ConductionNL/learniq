# Tasks: school-activities-staff-side

- [ ] **T1**: the staff page `Activiteiten` in learniq's menu, drawn on `learniq/LqActiviteiten`: the activities of the school with their places, confirmed sign-ups and waiting list
- [x] **T2**: open an activity: places are the lower of the capacity and the supervisors times the children per supervisor; an activity without a place is refused with `no_places` and stays a draft
  - `SchoolActivityStaff::open()` behind `PUT /api/school-activities/{id}/open`; places in `ActivityPlaces::placesFor()`.
- [x] **T3**: the roster of one activity, with a photo consent mark per child when the activity takes photos
  - `GET /api/school-activities/{id}/roster`: confirmed and waiting children, each with the signing guardian's `news` photo consent when `photosTaken`.
- [x] **T4**: attendance per session: present, absent or excused per confirmed child, a second mark updates the first, a child without a confirmed place is refused with `not_confirmed`
  - `PUT /api/school-activities/{id}/attendance` (`sessionId`, `childRef`, `status`), refused with 422 `unknown_session`, `not_confirmed` or `invalid_status`.
- [x] **T5**: read and write portaliq's `activityOffer`, sign-up and attendance records through OpenRegister (see the assumption in the proposal)
  - `ActivityRecords` reads and writes portaliq's `activityOffer`, `activitySignup`, `activityAttendance` and `guardianAudienceFixture` through OpenRegister; every endpoint sits behind the action `activity.manage`.
- [ ] **T6**: move the staff calls out of portaliq's admin once the learniq page works, so one staff side remains
