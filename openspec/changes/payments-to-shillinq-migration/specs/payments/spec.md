# Payments: reduced to FeeItem and Entitlement, payment through shillinq (D19)

## MODIFIED Requirements

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
- **THEN** the transition is refused regardless of whether shillinq reports a payment captured

#### Scenario: An unpaid voluntary Order does not change the learner's participation status elsewhere

<!-- @e2e exclude Cross-capability non-effect is a negative assertion over backend state (absence of any gating read), verified by PHPUnit; no scholiq DOM surface, since this capability defines no participation check itself (see design.md's fast-follow note on wiring enrolment/course-management consumption). -->

- **GIVEN** a learner whose guardian has an unpaid, overdue shillinq payment request for a `voluntary: true` `FeeItem`
  (e.g. a school trip)
- **WHEN** any other scholiq capability checks the learner's enrolment or attendance status
- **THEN** nothing in this capability's schema exposes a gating signal derived from that unpaid request:
  no `Entitlement` for a voluntary `FeeItem` can ever be `active` (per the scenario above)

## REMOVED Requirements

### Requirement: Persist Order and OrderLine as the payer-facing request for payment, with a validated total
**Reason**: A school contribution is a shillinq invoice or payment request with no learniq order (D12, D19).
**Migration**: Existing Order and OrderLine rows are exported to payments-archive/retired-payments.json by ArchiveRetiredPaymentObjects; an open order is raised again in shillinq.

### Requirement: Entitlement grants access only once its Order is paid, and only for non-voluntary chargeables
**Reason**: Replaced by the requirement that an Entitlement is granted only once shillinq reports its payment request settled.
**Migration**: Entitlement.orderLineId is removed; paymentRequestRef names the shillinq request.

### Requirement: Payment initiation and status delegate entirely to OpenConnector; scholiq implements no PSP wire protocol
**Reason**: Learniq starts no payments; shillinq takes payment through integriq and portaliq shows the pay screen.
**Migration**: PaymentTransactionController, its two routes and PaymentInitiationClient are removed; PaymentTransaction rows are archived.

### Requirement: Due-date reminders for open Orders are declarative notifications honouring quiet hours
**Reason**: Dunning and due-date reminders are shillinq's.
**Migration**: None in learniq; shillinq sends reminders for its own requests.

### Requirement: Frontend is declarative with one named view for initiating and tracking payment
**Reason**: Learniq has no pay screen; the pay action is portaliq's, contributed by shillinq.
**Migration**: OrderPaymentPanel and the Orders, OrderLines and PaymentTransactions pages are removed.

## ADDED Requirements

### Requirement: An Entitlement is granted only once shillinq reports its payment request settled

The `Entitlement` `grant` transition (pending → active) MUST pass `FeeItemVoluntaryEntitlementGuard` (a voluntary fee is always refused) and then `EntitlementPaymentSettledGuard`. The latter MUST allow the grant only when shillinq is installed, the Entitlement's `paymentRequestRef` names a `PaymentRequest` in shillinq's register, that request's `subject` names this Entitlement (`subjectKind: object`, register `learniq`, schema `entitlement`), and its `state` is `captured`. In every other case, including shillinq not installed, a read error or `captured_unapplied`, the grant MUST be refused. Learniq MUST NOT reference a shillinq class.

#### Scenario: A captured payment unlocks a paid course

- **GIVEN** a pending `Entitlement` for `leerling-001` on a non-voluntary contractonderwijs `FeeItem`
- **AND** shillinq holds `PaymentRequest` `pr-1` on that Entitlement in state `captured`, named in `paymentRequestRef`
- **WHEN** the `grant` transition is attempted
- **THEN** it succeeds

#### Scenario: Without shillinq nothing is granted

- **GIVEN** shillinq is not installed
- **WHEN** the `grant` transition is attempted on any Entitlement
- **THEN** it is refused with a message that payments run through shillinq

#### Scenario: A request on another object does not count

- **GIVEN** a captured `PaymentRequest` whose `subject` names a different Entitlement
- **WHEN** the `grant` transition is attempted
- **THEN** it is refused

### Requirement: A settled shillinq payment request grants its Entitlement and a voided one revokes it

When OpenRegister reports an update of an object shaped like a shillinq `PaymentRequest` (`paymentGateway` present, `subjectKind: object`, `subject` naming a learniq `entitlement`) whose `state` became `captured`, learniq MUST stamp `paymentRequestRef` and `paymentSettledAt` on that Entitlement when it is pending and fire its `grant` transition. When the state moved from `captured` to `voided`, learniq MUST fire `revoke` on the active Entitlement whose `paymentRequestRef` is that request. Any other update MUST be ignored, and a failure MUST NOT be thrown into shillinq's write.

#### Scenario: The pupil's course opens after the guardian pays

- **GIVEN** a pending Entitlement `ent-1` and a shillinq request `pr-1` on it
- **WHEN** shillinq saves `pr-1` with `state: captured`
- **THEN** `ent-1` carries `paymentRequestRef: pr-1` and a `paymentSettledAt`
- **AND** its `grant` transition is fired

#### Scenario: A voided payment closes the course again

- **GIVEN** an active Entitlement granted by `pr-1`
- **WHEN** shillinq saves `pr-1` from `captured` to `voided`
- **THEN** the Entitlement's `revoke` transition is fired

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
