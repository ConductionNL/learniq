---
kind: spec
depends_on: [site-workplace-trainer-portal-design]
---

# Proposal: internship-hours

## Why

The trainer mockup (`LearniqTrainer.dc.html`) puts hours at the centre of her week. Her overview says "Keur de uren van Daan goed — Week 39: 32 uur. Daan wacht hierop sinds maandag", her menu carries "Uren goedkeuren" with a count, and each student's card reads "Stage-uren 312 van 640".

**None of that has any data behind it today.** learniq has no record of hours worked at all: `BpvPlacement` holds the company, the trainer, the school coach and the period, and nothing else. `HourPlan` is the programme's planned study load, not a student's realised hours, and `AttendanceRecord` is about lessons at school. So the trainer's pages left hours out, and the e2e suites could not test what does not exist.

Hours are also not only a portal screen. A school counts BPV hours towards the qualification, the praktijkovereenkomst states them, and an inspection asks who approved them and when. That is why this is proposed as a record of its own rather than a widget.

## What changes

- **A new record: `BpvHourWeek`.** One week of one placement: the placement, the ISO week, the hours the student entered, who entered them, the hours the trainer approved, who approved them and when, and a lifecycle (`submitted`, `approved`, `corrected`, `rejected`). Hours stay on the week, never on the placement, so a correction is visible rather than silent.
- **A total to count against.** `BpvPlacement` gains `agreedHours` — the hours the placement agreement states. "312 van 640" then reads off a real numerator and a real denominator, and a placement without an agreed total shows hours without a progress bar instead of inventing one.
- **The trainer's portal gains two things**: a collection of the weeks waiting for her, and an action to approve or correct one. Both scope by `practicalTrainerId`, like everything else she reads.
- **The pupil's portal gains one**: she enters her own week, scoped by `learnerRef`.
- **The school's own screens** gain the hours on the placement detail page and a list per cohort, because a mentor is asked "how far is Daan" far more often than a trainer is.

## What this does not do

- It does not touch the POK. A praktijkovereenkomst states agreed hours; whether a changed `agreedHours` needs a new signature is a question for the POK change, and this proposal only records that the two now meet.
- It does not pay anybody. Hours here are evidence of learning time, not a timesheet for wages.
- It invents no approval chain beyond the trainer. The school coach sees the weeks; the mockup gives him no verdict, so he gets none.

## Open question for Ruben

A week the trainer **corrects** downwards is the normal case in practice ("hij was donderdag twee uur eerder weg"). Does the corrected week need the student's acknowledgement before it counts, or is the trainer's word final? The spec below takes the trainer's word as final and records both numbers, which is the simpler of the two and keeps the dispute visible.
