# Proposal: admin-page-declared-sections

## Why

The admin page was nine hand-written sections and a 1,000-line settings view.
Three of them wrote what the server should write: the processing agreement's
signing date was replaced with "now" on every save, and the reviewer fields came
from the browser (audit S8). App configuration also lived inside the app: the
timetable visibility policy on the timetable lookup, the room opening hours on
the room report, and the kind of organisation on an in-app `/settings/learniq`
page (audit Q7, accepted by Ruben).

## What changes

- The admin page is CnAdminSettingsShell with the tabs of board LqInstellingen.
  Each tab's sections are declared in `src/adminSettings.js` over library
  components: CnSettingsFieldsSection, CnShippedConfigurationSection,
  CnRecordListWidget and CnActionAuthMatrix. learniq's AI translation review
  stays its own section.
- The kind of organisation, the timetable visibility policy and the room opening
  hours move to the admin page. The in-app `/settings/learniq` routes go.
- The Compliance record is saved through `PUT /api/admin/compliance`; a save
  listener writes `privacyconvenantSignedAt` (kept while the agreement stays
  signed) and `lastReviewedBy/At`.
- The processing activities shown come from the register's
  `x-openregister-processing` annotations (`GET /api/admin/processing-activities`)
  instead of a hand-kept list of ten.
- The visibility policy is saved through `PUT /api/timetable/visibility-policy`.
- The settings answer says whether Hermiq is installed; the setup status says
  which segment is in force.

## Depends on

Unreleased nextcloud-vue: CnActionAuthMatrix
(`feature/lq-abstract-lq5-action-auth-matrix`), settings fields growth
(`feature/lq-abstract-lq5-settings-fields` on `pl-abstract-pl5-settings-fields`)
and loaded sets (`feature/lq-abstract-lq5-shipped-sets` on
`dq-abstract-l2b-admin-sections`).
