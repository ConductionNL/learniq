# payments Specification

## Purpose
TBD - created by archiving change school-payments. Update Purpose after archive.

## Requirements

### Requirement: Persist FeeItem as the chargeable definition, including its voluntary posture

The system MUST persist `FeeItem` as an OpenRegister object naming what is being charged for: `kind`
(`course-enrolment | school-trip | materials | schoolkassa | mbo-contractonderwijs | other`), `amount`
(non-negative number), `currency` (default `EUR`), an optional `taxPosture` (descriptive metadata only —
`education-exempt | standard-rate | reduced-rate | not-applicable` — not a computed VAT engine), an
optional `linkedCourseId` ($ref `Course`, for `course-enrolment`/`mbo-contractonderwijs` kinds), an
optional `linkedCohortId` ($ref `Cohort`, for `school-trip` kinds), and a required `voluntary` boolean
(default `false`). `voluntary: true` MUST be used for any ouderbijdrage-style contribution (schoolkassa,
trips, materials a school chooses to make voluntary); `voluntary: false` MUST be used for a genuine paid
product (contractonderwijs, a paid course). `FeeItem` MUST carry `x-openregister-lifecycle`
(`draft → active → archived`), mirroring `AttendanceThreshold`'s configuration-object shape.

#### Scenario: A schoolkassa contribution is declared voluntary

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by PHPUnit schema-validation tests, no scholiq DOM surface for schema registration itself. -->

- **GIVEN** an administrator authoring a schoolkassa `FeeItem` for a school trip
- **WHEN** they save it with `kind: school-trip` and `voluntary: true`
- **THEN** the `FeeItem` persists with `voluntary: true`
- **AND** it is available to a shillinq payment request raised on an `Entitlement`

#### Scenario: A contractonderwijs course is declared non-voluntary

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; PHPUnit schema-validation coverage only. -->

- **GIVEN** an MBO administrator authoring a `FeeItem` for a paid contractonderwijs course
- **WHEN** they save it with `kind: mbo-contractonderwijs`, `voluntary: false`, and `linkedCourseId` set
- **THEN** the `FeeItem` persists with `voluntary: false`
- **AND** it is eligible to back an `Entitlement` once paid (see the Entitlement requirement below)

### Requirement: A voluntary FeeItem MUST NOT gate enrolment or participation

The system MUST structurally prevent a voluntary `FeeItem` from ever gating enrolment or participation, per
the Wet vrijwillige ouderbijdrage (in force since 1 August 2021): non-payment of a voluntary contribution
MUST NOT exclude a pupil from the activity it funds, and offering a lesser/substitute activity to
non-payers is equally non-compliant. A new `FeeItemVoluntaryEntitlementGuard` MUST block the `Entitlement`
`pending → active` ("grant") transition
whenever the `Entitlement`'s referenced `FeeItem.voluntary` is `true`. Because nothing in this capability
gates access on a `pending` `Entitlement` (only `active` ones grant anything — see the next requirement),
this makes it structurally impossible for a voluntary fee to ever become an access gate through this
capability's own mechanism.

#### Scenario: An Entitlement referencing a voluntary FeeItem can never activate

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit FeeItemVoluntaryEntitlementGuardTest::testVoluntaryFeeItemBlocksGrantRegardlessOfPaymentState; no scholiq DOM surface for the guard itself. -->

- **GIVEN** an `Entitlement` in `pending` state whose `feeItemId` references a `FeeItem` with
  `voluntary: true`
- **WHEN** an attempt is made to transition the `Entitlement` from `pending` to `active`
- **THEN** the transition is refused regardless of whether shillinq reports the contribution settled

#### Scenario: An unpaid voluntary Order does not change the learner's participation status elsewhere

<!-- @e2e exclude Cross-capability non-effect is a negative assertion over backend state (absence of any gating read), verified by PHPUnit; no scholiq DOM surface, since this capability defines no participation check itself (see design.md's fast-follow note on wiring enrolment/course-management consumption). -->

- **GIVEN** a learner whose guardian has an unpaid, overdue shillinq payment request for a `voluntary: true` `FeeItem`
  (e.g. a school trip)
- **WHEN** any other scholiq capability checks the learner's enrolment or attendance status
- **THEN** nothing in this capability's schema exposes a gating signal derived from that unpaid request:
  no `Entitlement` for a voluntary `FeeItem` can ever be `active` (per the scenario above)

### Requirement: An Entitlement is granted only once shillinq reports its payment request settled

The `Entitlement` `grant` transition (pending → active) MUST pass `FeeItemVoluntaryEntitlementGuard` (a voluntary fee is always refused) and then `EntitlementPaymentSettledGuard`. The latter MUST allow the grant only when shillinq is installed, the Entitlement's `paymentRequestRef` names a `PaymentRequest` in shillinq's register, that request carries `settledAt` (shillinq contract extracurricular-fee-to-shillinq v1), its `subject` is this Entitlement's FeeItem (`subject.app` learniq) and its `beneficiary` this Entitlement's learner. In every other case, including shillinq not installed, a read error, a captured request without `settledAt`, or a request for another fee or learner, the grant MUST be refused. Learniq MUST NOT reference a shillinq class.

#### Scenario: A settled contribution unlocks a paid course

- **GIVEN** a pending `Entitlement` for `leerling-001` on a non-voluntary contractonderwijs `FeeItem` `fee-1`
- **AND** shillinq holds `PaymentRequest` `pr-1` for `fee-1` and `leerling-001` with `settledAt` set, named in `paymentRequestRef`
- **WHEN** the `grant` transition is attempted
- **THEN** it succeeds

#### Scenario: Without shillinq nothing is granted

- **GIVEN** shillinq is not installed
- **WHEN** the `grant` transition is attempted on any Entitlement
- **THEN** it is refused with a message that payments run through shillinq

#### Scenario: A request for another learner does not count

- **GIVEN** a settled `PaymentRequest` for `fee-1` whose `beneficiary` is `leerling-009`
- **WHEN** the `grant` transition is attempted on `leerling-001`'s Entitlement
- **THEN** it is refused

### Requirement: A settled shillinq contribution grants the learner's entitlement

When OpenRegister reports an update of a shillinq `PaymentRequest` (register `shillinq`, schema `PaymentRequest`) whose old object has no `settledAt`, whose new object has one, and whose `subject.app` is learniq, learniq MUST find the pending Entitlements for the request's FeeItem (`subject.id`) and learner (`beneficiary`), stamp `paymentRequestRef`, `paymentSettledAt` and `paymentSettledVia` on each and fire its `grant` transition. Any other update MUST be ignored, and a failure MUST NOT be thrown into shillinq's write.

#### Scenario: The course opens after the guardian pays

- **GIVEN** a pending Entitlement `ent-1` for `fee-1` and `leerling-001`
- **WHEN** shillinq saves `pr-1` for `fee-1` and `leerling-001` with `settledAt` for the first time
- **THEN** `ent-1` carries `paymentRequestRef: pr-1`, the `paymentSettledAt` and the `paymentSettledVia`
- **AND** its `grant` transition is fired

#### Scenario: A later save of a settled request changes nothing

- **GIVEN** `pr-1` already carries `settledAt`
- **WHEN** shillinq saves it again
- **THEN** no transition is fired

### Requirement: A school raises a fee's contributions in shillinq from learniq

An administrator holding `fee-item.raise-contributions` MUST be able to raise an active FeeItem's contributions in shillinq with `POST /apps/learniq/api/fee-items/{id}/contributions`, offered as an action on the FeeItem page. Learniq MUST send shillinq's contract request: the FeeItem as `chargeable` (`app: learniq`), one recipient per learner of the fee's group or course, the first guardian with an e-mail address (or the learner without guardians) as `debtor`, and the learner as `beneficiary`, in chunks of at most 200. A learner nobody can be mailed about MUST be reported, not sent. For a non-voluntary fee that unlocks something, each learner MUST end with one pending Entitlement carrying the returned `paymentRequestId`; a voluntary fee MUST create none. Without shillinq the endpoint MUST answer 503 and raise nothing; without a shillinq administration it MUST answer 400.

#### Scenario: The ouderbijdrage goes to the parents of group 7a

- **GIVEN** a voluntary schoolkassa FeeItem of 60 euro for group 7a, whose learners have guardians with e-mail addresses
- **WHEN** the administrator raises its contributions
- **THEN** shillinq receives one recipient per learner with kind `parental-contribution` and `voluntary: true`
- **AND** no Entitlement is created

#### Scenario: A paid course waits for its payment

- **GIVEN** a non-voluntary course FeeItem and an employee enrolled on the course
- **WHEN** the administrator raises its contributions
- **THEN** the employee has a pending Entitlement whose `paymentRequestRef` is the request shillinq returned

#### Scenario: Raising twice is safe

- **GIVEN** the contributions were raised once
- **WHEN** the administrator raises them again
- **THEN** shillinq answers `skipped` and no second Entitlement appears

### Requirement: Retired payment rows are archived before their schemas go

On upgrade, before the register import, learniq MUST write every `order`, `order-line` and `payment-transaction` row to `payments-archive/retired-payments.json` in its app data folder, with the export time, the reason and the count per schema. The step MUST do nothing when that file exists, MUST write nothing when there are no rows, and MUST NOT delete the rows.

#### Scenario: A school's orders are kept for the accountant

- **GIVEN** two orders, one order line and one payment transaction
- **WHEN** the upgrade runs
- **THEN** `retired-payments.json` holds all four, counted per schema

#### Scenario: A second upgrade writes nothing

- **GIVEN** the archive exists
- **WHEN** the upgrade runs again
- **THEN** the file is left as it is

### Requirement: Learniq keeps FeeItem and Entitlement and no pay screen of its own

Learniq MUST keep the `FeeItem` and `Entitlement` index and detail pages, listed under People. It MUST NOT ship an order, order line or payment transaction schema, page or route, nor a pay screen: the pay action is portaliq's, contributed by shillinq.

#### Scenario: An administrator looks for fee items

- **GIVEN** an administrator opens the People menu
- **WHEN** they look for fee items and entitlements
- **THEN** both are listed there, and there is no Payments menu
