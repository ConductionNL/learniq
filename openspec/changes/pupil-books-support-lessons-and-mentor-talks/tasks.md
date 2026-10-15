# Tasks: pupil-books-support-lessons-and-mentor-talks

- [ ] **T1**: student audience: `studentElectiveOffers` (projection with teacher name, next date, places-left line) and `signUpForElective`, through the existing sign-up rules
- [ ] **T2**: register: conference round `pupilMayBook`; the booking guard accepts the learner herself when set, one booking per learner per round
- [ ] **T3**: student audience: `studentConferenceTimes` and `bookConferenceSlotAsPupil`; the guardian notice on a pupil's booking
- [ ] **T4**: the invitation notice to a pupil links "Kies een tijd" to the booking page (D-5)
- [ ] **T5**: vo example set: the steunles wiskunde A (Tuesdays, 8th hour, 2.14, capacity 12, 6 left), mentor-talk round H4b 13 and 15 October with `pupilMayBook: true`
- [ ] **T6**: e2e in `tests/e2e/portal-design/vaartveld.spec.ts`: Noor signs up for the steunles and books Tuesday 16.30; Erik sees it
