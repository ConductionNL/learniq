## ADDED Requirements

### Requirement: Each example set is the school its portal was designed for

The curated example sets MUST carry the schools of the four portal designs: po MUST be "Basisschool De Wilgenboom", vo MUST be "Vaartveld College", mbo MUST be "Esdoornveen" and training MUST be the "Warmtepompacademie", each in the fictional town of Zuiddrecht. Each set MUST contain the people its design names, with the dates and numbers its boards show, pinned to Monday 5 October 2026. A number on a board MUST come out of the data (an average from its grade entries, an hours total from its hour weeks), not from a stored copy that disagrees with them.

#### Scenario: The po set holds the Hulstkamp family's autumn
- **GIVEN** a fresh instance
- **WHEN** the operator loads the po set
- **THEN** Fatima Hulstkamp is the guardian of Vera (Groep 7, po-leerkracht-09) and Sami (Groep 4, po-leerkracht-07)
- **AND** Sami has an undecided absence report for 5 October 2026 reading "Sami heeft buikgriep", marked by his teacher at 8.12
- **AND** for school year 2026-2027 Vera has 1 day absent and 1 late arrival of 10 minutes, Sami 2 days absent
- **AND** Vera's parent-evening time is Thursday 29 October 2026 18.00 to 18.10, acknowledged
- @e2e exclude seed data with no screen of its own, covered by PHPUnit `PrimarySchoolExampleSetTest::testTheWilgenboomStoryIsInTheData`; the portal screens over it are checked by `tests/e2e/portal-design/wilgenboom.spec.ts`

#### Scenario: The vo set holds Noor Bakker's Monday
- **GIVEN** a fresh instance
- **WHEN** the operator loads the vo set
- **THEN** Vaartveld College has a pupil Noor Bakker in class H4b with seven sessions on 5 October 2026, of which lesson 3 has a room change and lesson 7 is cancelled
- **AND** her wiskunde A grade entries 6,1 (weight 1), 4,7 (weight 3) and 5,8 (weight 1) average 5,2
- @e2e exclude seed data, covered by PHPUnit `SecondarySchoolExampleSetTest`

#### Scenario: The mbo set holds Milan de Groot's placement
- **GIVEN** a fresh instance
- **WHEN** the operator loads the mbo set
- **THEN** Esdoornveen has a student Milan de Groot in Mechatronica niveau 4 (crebo 25743) with a placement at Bakker Techniek BV whose agreed hours are 480
- **AND** his hour weeks give 96 hours approved, 16 waiting and 8 returned
- @e2e exclude seed data, covered by PHPUnit `VocationalCollegeExampleSetTest`

#### Scenario: The training set holds Jansen Installatietechniek's enrolments
- **GIVEN** a fresh instance
- **WHEN** the operator loads the training set
- **THEN** the Warmtepompacademie has Tom Verbeek, Youssef El Amrani and Sanne Kok enrolled for "F-gassen: herhaling en examen" on 8 October 2026, and Youssef has no birth date
- **AND** their F-gassen certificates expire on 30 November 2026
- @e2e exclude seed data, covered by PHPUnit `TrainingExampleSetTest`

#### Scenario: Reloading a renamed set moves no stored object
- **GIVEN** an instance that loaded the po set before this change
- **WHEN** the operator loads the po set again
- **THEN** every object keeps its uuid and slug, the story objects are added, and `occ learniq:example-set:remove po` still removes exactly the set's objects
- @e2e exclude covered by `python3 scripts/example-sets/po.py --check` and PHPUnit `ExampleSetDescriptorContractTest`
