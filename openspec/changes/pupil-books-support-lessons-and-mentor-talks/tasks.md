# Tasks: pupil-books-support-lessons-and-mentor-talks

- [ ] **T1**: student audience: `studentElectiveOffers` (projection with teacher name, next date, places-left line) and `signUpForElective`, through the existing sign-up rules
- [x] **T2**: register: conference round `pupilMayBook`; the booking guard accepts the learner herself when set, one booking per learner per round
  - `conference-round.pupilMayBook` (0.3.0, default false). `ConferenceSlotBookingStamp` takes a portal booking without a `guardianRef` as the pupil's own: refused as `slot-pupil-not-allowed` unless the round sets the flag, `signup-learner-unknown` for a learner it cannot find; otherwise the same claim books the slot with no guardian on it. The claim counts bookings per learner per round, so a pupil's and a guardian's booking exclude each other.
- [ ] **T3**: student audience: `studentConferenceTimes` and `bookConferenceSlotAsPupil`; the guardian notice on a pupil's booking
- [ ] **T4**: the invitation notice to a pupil links "Kies een tijd" to the booking page (D-5)
- [ ] **T5**: vo example set: the steunles wiskunde A (Tuesdays, 8th hour, 2.14, capacity 12, 6 left), mentor-talk round H4b 13 and 15 October with `pupilMayBook: true`
- [ ] **T6**: e2e in `tests/e2e/portal-design/vaartveld.spec.ts`: Noor signs up for the steunles and books Tuesday 16.30; Erik sees it
