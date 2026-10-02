# Design: direct conference booking

## States

| ConferenceSlot | meaning | who moves it |
|---|---|---|
| `free` | a time a parent may pick | ConferenceFreeSlotGenerator writes it |
| `booked` | a parent picked it | the portal booking (`book`) |
| `acknowledged` | the teacher accepts | the teacher (`acknowledge`) |
| `declined` | the teacher cannot, with `declineNote` | the teacher (`decline`) |
| `cancelled` | the parent cancelled, or the school removed a free time | the parent in the portal, or staff |
| `completed`, `no-show` | after the evening | the teacher |

The booking (`ConferenceSignup`) follows the slot: `booked`, then `acknowledged`, `declined` or `cancelled`.

## Why a released time becomes a new slot

A declined or cancelled slot keeps who booked it and the teacher's note, so the parent still reads why. A new `free` slot for the same teacher and time is written while the round is `booking-open`. Moving the old slot back to `free` would need a second transition into `free` from the same states, which OpenRegister's save path cannot tell apart, and would erase the note the parent has to read.

## Why the claim locks

The check ("is the slot free, may this child book it, has the child no other time") and the write must not interleave between two requests. Both run under exclusive Nextcloud locks on `slot/<slot>` and `booking/<round>/<child>`, hashed into `learniq/conference/<md5>` because the database lock table keeps 64 characters. A held lock is a refusal, not a wait. The slot is read inside the locks from storage (`ObjectService::find` keeps no object data between calls). Nextcloud's locking provider is the database by default. With `filelocking.enabled` false it is a no-op, and only the re-read inside the claim remains.

## Migration

No data moves. A round without `bookingMode` reads as `preference` (`ConferenceBookingMode::of`). `ConferenceRoundBookingModeStamp` fills the value on creates only.

## Why the parent contribution declares its pages

Portaliq builds a default page per collection and puts the first create form of the collection's schema on it. Both conference forms create a `conference-signup`, so the request form of a planned round disappeared behind "Book a time". `ParentPortalCollections::pages()` builds the same pages (same ids, so the site's routes stay) and puts "Book a time" on the free times page and the request form on the bookings page.
