## ADDED Requirements

### Requirement: A set may declare events and a news item may name one

An example set's portal declaration MAY list `events`, each a portaliq `schoolEvent`. Loading the set MUST write each declared event that does not exist yet, matched by title, as `published`, and MUST NOT write over an event that exists. A declared news item MAY name an event by its title in `event`; the load MUST write that event's id as the item's `eventRef` and MUST NOT write the `event` key. The events' dates MUST move with the load week like the pages and news.

#### Scenario: the vo set publishes the evening with its event
- GIVEN the vo set declares the event "Informatieavond profielkeuze, klas 3" and a news item that names it
- WHEN the set is loaded on a portal without them
- THEN the event is written once, published, with `rsvpBy` guardian and learner and seats asked
- AND the news item carries the event's id as `eventRef` and no `event` key

#### Scenario: a second load writes no second event
- GIVEN the event exists
- WHEN the set is loaded again
- THEN no event is written and the load reports it as kept

#### Scenario: a news item names an event that is not there
- GIVEN a news item names an event the set does not declare
- WHEN the set is loaded
- THEN the item is written without `eventRef` and without `event`
