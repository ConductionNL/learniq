# Proposal: parent-row-actions-only-where-they-apply

## Why

Ruben reviewed the primary-school parent portal (2026-10-02). On "Uw gesprekstijden" every time offered "Deze tijd annuleren", also a finished, cancelled or declined one. Pressing it there only produced an error: ConferenceSlotBookingSync refuses a parent cancel unless the time is `booked` or `acknowledged` and the booking window is still open. Portaliq now lets a `type: update` row action say on which rows it applies (`rowWhen`, portaliq update-row-action-condition).

## What changes

- `cancelConferenceTime` declares `rowWhen: {field: lifecycle, in: [booked, acknowledged]}`, so the site shows the cancel only on a time the guardian can still cancel.
- A test pins that every row action learniq contributes declares a `rowWhen` on a projected field, with states the schema's lifecycle has. The pupil's `handIn` already did (`draft`).

## Not changed

- The booking window. The slot row carries no closing date, and `{field, in}` cannot compare dates, so a booked time in a closed round still shows the cancel; ConferenceSlotBookingSync keeps refusing it with its own message.
- The server's checks. The condition only hides a button.
- Every other key of the manifest: a dump of all four audiences before and after differs only in this `rowWhen`.
