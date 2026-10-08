## ADDED Requirements

### Requirement: The portal declarations follow their boards

Each portal declaration MUST give the footer one Contact column, from `footer.contact`, and no footer menu named "Contact". Every school hero MUST declare `variant: plain`, and a hero with a search box MUST declare `headingVisible: true`. A banner MUST be the notice strip: `kind: notice`, a lead, `band: true`, not closable. On the po and vo home pages the sign-in card and the calendar MUST stand beside the news, the calendar right under the card. The content page that explains how to report absence (po, vo, mbo) or enrol staff (training) MUST open with its title as a level-1 `nlHeading`, put its side list at the top of the right column, keep every other block in the left 8 columns, and show its table `boxed`. The mbo home MUST show "Kies je richting" over four cards. No school board shows portaliq's own case, task or access items, so every school portal MUST declare `residentMenu.leaveOut: [cases, tasks, access]`.

#### Scenario: A fresh load renders one footer Contact column and one page title
- **GIVEN** a fresh instance
- **WHEN** the operator loads the po set
- **THEN** the footer has one Contact column and the page opens with "Uw kind afwezig melden" as its level-1 heading
- @e2e exclude declaration file, covered by PHPUnit `ExamplePortalDeclarationsTest::testTheDeclarationsFollowTheBoards`; the rendered pages are compared by `tests/e2e/portal-design/`
