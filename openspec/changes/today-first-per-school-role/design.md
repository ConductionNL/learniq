# Design: today-first-per-school-role

## The First today card is one ordered list of rules per role

Each rule is a named query plus a sentence template. The card shows the first rule, in this order, that returns at least one row. The order is the decision of this change, so a reviewer can change it cheaply:

| Role | Rules, first wins |
|---|---|
| group teacher (po) | 1. today's register not filled after the first lesson started, with the number of parent reports in it; 2. bookings waiting for my confirmation; 3. a pupil of my group nearing the attendance limit |
| subject teacher (vo, mbo) | 1. a lesson of mine today whose register is not filled after it ended; 2. a pupil of my classes nearing the attendance limit; 3. a submission deadline today with missing hand-ins |
| mentor | 1. a pupil of my class with unauthorised absence in the last two weeks not yet handled; 2. mentor talks: the booking closes within two school days and pupils have not chosen |
| intern begeleider | 1. a plan that ends within seven days without a planned evaluation; 2. a signal from a teacher not yet taken up |
| directeur, teamleider | 1. leave requests past half their decision time; 2. pupils over the attendance limit not yet reported; 3. registers not filled at 9.30 |

## Mentor is a view, not a role

`simple-today-dashboard` notes that the server resolves no mentor role. This change does not add one: a teacher is a mentor of every cohort whose `mentorId` is her user id. The switch appears only for such a teacher. Access stays with the existing rules; the view only narrows.

## Counts open lists

Every tile keeps the rule of `simple-today-dashboard`: the number opens the list it counts with the same filter. A tile whose list does not exist is not drawn.
