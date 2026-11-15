# Proposal: a fast roll-call for the group teacher

## Why

Ruben (2026-10-02): "Teachers should be able to easily record absence (including unallowed) or lateness of students."

Today a teacher takes the register per lesson (`/sessions/:sessionId/attendance`). In a primary school that means finding today's lesson in a long list first. The teacher dashboard's "Sessions to mark" lists the oldest sessions first and opens the session's detail page, not the register. Late arrivals have no minutes, an absence has no reason category, and an absence report a parent filed and the school approved does not show up in the register.

## What changes

- A new page, "Today's register" (`/attendance/roll-call`), opens the register of one group for one day. A group teacher lands on their own group and today. Coordinators and administration-managers can pick any group.
- Everyone starts as present. One tap or key per exception: late (5, 10 or 15 minutes, or a custom number), absent with permission (ill, appointment or other) and absent without permission. One save writes the day.
- An approved absence report that covers the day pre-fills "absent with permission" with the reason from the report and links the report to the record. A report that still waits for a decision is shown, not applied.
- A group teacher can change the register the whole day. An earlier day is read-only for them once it has been saved; an earlier day nobody saved yet can still be filled in. Coordinators and administration-managers can change any day up to today. Nobody saves a day in the future.
- A day without a lesson for the group gets one when the register is saved, with the group's usual times.
- `AttendanceRecord` gains two optional fields: `lateMinutes` and `absenceReasonKind` (`illness`, `appointment`, `other`). The existing statuses already tell absence with permission (`absent-excused`) from absence without (`absent-unexcused`).
- `GET` and `POST /api/attendance/roll-call` serve and save the page. The server decides who may open which group and writes the records itself, so a coordinator does not need write rights on `AttendanceRecord`.
- The teacher's menu gets "Today's register". On the teacher dashboard, "Sessions to mark" lists the lessons up to today, newest first, and opens the roll-call for that group and day.

## Out of scope

- The attendance summary per learner per school year (change `attendance-summary-per-school-year`).
- The parent portal's attendance cards (lane lq-record).
- The per-lesson register stays as it is.
