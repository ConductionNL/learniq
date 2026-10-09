<?php

/**
 * Learniq Portal Contribution Provider
 *
 * Learniq's contribution to the shared Portaliq external portal (hydra ADR-046
 * + contribution contract v2, 2026-07-06 amendment). Portaliq — the ONE shared
 * portal for people WITHOUT Nextcloud accounts — discovers this class by
 * convention FQCN (`OCA\{Namespace}\Portal\PortalContributionProvider`) and
 * duck-types it via method_exists(), never instanceof. This class is therefore
 * deliberately PLAIN: no portaliq imports, no `implements` clause, no info.xml
 * dependency, and only one optional constructor dependency (the l10n factory
 * that puts the guardian's labels in her language). Without portaliq installed it is
 * inert and Learniq behaves exactly as before (amendment A1).
 *
 * It declares — for the `student` (the learner) and `parent` (a guardian)
 * audiences — the OpenRegister collections a portal subject may read, the
 * whitelisted create-actions they may perform, and (student only) a
 * notification inbox. All scoping is by UUID DOMAIN-OBJECT references
 * (`learnerRef` = a LearnerProfile object UUID; `guardianRef` = a guardian
 * domain UUID) added by the `portal-identity` change — never a Nextcloud user
 * id, because an external subject has no Nextcloud account by premise
 * (amendment A4). Learniq's internal `learnerId` / `parentIds` / `submittedBy`
 * flows are untouched.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\Learniq\Service\Portal\BpvPlacementSteps;
use OCA\Learniq\Service\Portal\EmployerBookingSteps;
use OCP\L10N\IFactory;

/**
 * Declares what an external portal subject may see and do in Learniq.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks). All subject identity (subjectRef, audience, organisation, trust)
 * is derived server-side by portaliq's auth edge and MUST never be trusted
 * from the client (ADR-005). Scoping uses UUID domain refs — the student's own
 * `LearnerProfile` object UUID (`learnerRef`), or a guardian domain UUID
 * (`guardianRef`) resolved one hop to the child learner via
 * `LearnerProfile.guardianRefs`.
 *
 * Field-projected surfaces: every read collection ships an explicit `fields`
 * whitelist that drops staff-only/internal columns (grader identity + private
 * comments on grades, the teacher's marking internals on submissions, the
 * marker and internal links on attendance, the staff decision fields on excuse
 * requests). Whitelist tables + the claim-names contract:
 * openspec/changes/archive/2026-09-28-portal-contribution/design.md. The parent reverse /
 * scope-value join (`match: 'scopeField'`) + its minTrust story:
 * openspec/changes/archive/2026-09-28-portal-parent/design.md.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The provider is the one place portaliq meets every
 *   audience, so it names each audience's declaration class.
 */
class PortalContributionProvider {
	/**
	 * The OpenRegister register slug every collection/action below lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * Constructor; the container hands in the factory, `new` with no arguments answers in English.
	 *
	 * @param IFactory|null             $l10nFactory    Puts parent labels in the request's language (PortalLabelTranslator).
	 * @param EmployerBookingSteps|null $bookingSteps   Answers a company booking's steps (employer-portal-audience).
	 * @param BpvPlacementSteps|null    $placementSteps Answers a work placement's steps (site-workplace-trainer-portal-design).
	 * @param PortalMessageContacts|null $messageContacts Who a resident may write to (portal-message-contacts).
	 * @param PortalPublicIndex|null     $publicIndex     What a visitor may find (portal-public-index).
	 */
	public function __construct(
		private readonly ?IFactory $l10nFactory=null,
		private readonly ?EmployerBookingSteps $bookingSteps=null,
		private readonly ?BpvPlacementSteps $placementSteps=null,
		private readonly ?PortalMessageContacts $messageContacts=null,
		private readonly ?PortalPublicIndex $publicIndex=null,
	) {
	}//end __construct()

	/**
	 * What a portal's visitor may find without signing in (portal-public-index).
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function getPublicIndex(string $portal): array {
		return ($this->publicIndex?->forPortal(portal: $portal) ?? []);
	}//end getPublicIndex()

	/**
	 * The steps of a work placement, for the student's and the trainer's placement page.
	 *
	 * Called by portaliq with the placement's id after its visibility check; none without the service.
	 *
	 * @param string $id The placement's uuid.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-placement-shows-where-it-stands
	 */
	public function bpvPlacementSteps(string $id): array {
		return ($this->placementSteps?->forPlacement(placementId: $id) ?? []);
	}//end bpvPlacementSteps()

	/**
	 * Who a guardian may write to about one child (the `contacts` provider
	 * of `parentChildren`): the teachers of the child's current groups.
	 * Portaliq calls this only for a child it read through the guardian's
	 * own scoped collection (portal-message-contacts).
	 *
	 * @param string $id The child's learner profile id.
	 *
	 * @return array<int, array{staffRef: string, name: string, role: string}>
	 *
	 * @spec openspec/changes/portal-message-contacts/specs/portal-contribution/spec.md#requirement-a-guardian-and-a-pupil-may-write-to-the-teachers-of-the-pupils-current-groups
	 */
	public function childMessageContacts(string $id): array {
		return ($this->messageContacts?->childContacts(profileId: $id) ?? []);
	}//end childMessageContacts()

	/**
	 * The `contacts` provider of `studentEnrolments`: the teachers of an active enrolment's group.
	 *
	 * @param string $id The enrolment id.
	 *
	 * @return array<int, array{staffRef: string, name: string, role: string}>
	 *
	 * @spec openspec/changes/portal-message-contacts/specs/portal-contribution/spec.md#requirement-a-guardian-and-a-pupil-may-write-to-the-teachers-of-the-pupils-current-groups
	 */
	public function ownMessageContacts(string $id): array {
		return ($this->messageContacts?->ownContacts(enrolmentId: $id) ?? []);
	}//end ownMessageContacts()

	/**
	 * The audiences this provider contributes to (contract v2, preferred).
	 *
	 * The registry probes for this method first. Learniq serves the learner
	 * (`student`) and their guardian (`parent`).
	 *
	 * @return array<int, string> The audience identifiers.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/specs/bpv/spec.md#requirement-praktijkopleider-portal-access-is-a-direct-scope-portalcontributionprovider-audience
	 * @spec openspec/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-reads-only-her-own-companys-people-and-bookings
	 */
	public function getAudiences(): array {
		return ['student', 'parent', 'praktijkopleider', 'external-assessor', EmployerSitePages::AUDIENCE, ParticipantSitePages::AUDIENCE];
	}//end getAudiences()

	/**
	 * The primary audience this provider contributes to (contract v1 fallback).
	 *
	 * Kept alongside getAudiences() so the provider also works against a v1
	 * registry that predates multi-audience support.
	 *
	 * @return string The primary audience identifier.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function getAudience(): string {
		return 'student';
	}//end getAudience()

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * The subject array is server-derived by portaliq (subjectRef UUID,
	 * audience, organisation, trust level low|substantial|high). Returns null
	 * for any audience Learniq does not serve (fail-closed; the registry
	 * already filters by audience, but a provider must not rely on that).
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<string, mixed>|null The manifest, or null when not contributing.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function getContribution(array $subject): ?array {
		$audience = $subject['audience'] ?? '';

		if ($audience === 'student') {
			// The pupil reads her labels in her language too (site-pupil-portal-design).
			return (new PortalLabelTranslator(l10n: $this->l10nFactory?->get('learniq')))->translate(manifest: $this->studentContribution());
		}

		if ($audience === 'parent') {
			return (new PortalLabelTranslator(l10n: $this->l10nFactory?->get('learniq')))->translate(manifest: $this->parentContribution());
		}

		if ($audience === 'praktijkopleider') {
			// The trainer and the assessor read their labels in their language too.
			return (new PortalLabelTranslator(l10n: $this->l10nFactory?->get('learniq')))->translate(manifest: (new TrainerSitePages())->contribution());
		}

		if ($audience === 'external-assessor') {
			// The trainer and the assessor read their labels in their language too.
			return (new PortalLabelTranslator(l10n: $this->l10nFactory?->get('learniq')))->translate(manifest: (new AssessorSitePages())->contribution());
		}

		if ($audience === EmployerSitePages::AUDIENCE) {
			// A company that sends its people to the courses (employer-portal-audience).
			return (new PortalLabelTranslator(l10n: $this->l10nFactory?->get('learniq')))->translate(manifest: (new EmployerSitePages())->contribution());
		}

		if ($audience === ParticipantSitePages::AUDIENCE) {
			// A course participant at a training institute (participant-portal).
			return (new PortalLabelTranslator(l10n: $this->l10nFactory?->get('learniq')))->translate(manifest: (new ParticipantSitePages())->contribution());
		}

		// Any audience Learniq does not serve → null (fail-closed; ADR-005).
		return null;
	}//end getContribution()

	/**
	 * The steps of a company booking, for the employer's booking page.
	 *
	 * Portaliq calls the provider named by `employerBookings.steps.provider`
	 * with the booking's id, after it checked that the employer may see that
	 * booking. Without the service (a test, an older container) there are no
	 * steps, never an error.
	 *
	 * @param string $id The booking's uuid.
	 *
	 * @return array<int, array<string, string>>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function employerBookingSteps(string $id): array {
		return ($this->bookingSteps?->forBooking(bookingId: $id) ?? []);
	}//end employerBookingSteps()

	/**
	 * Manifest for the `student` audience (the learner themself).
	 *
	 * `subject.subjectRef` is the student's own `LearnerProfile` object UUID.
	 * Every read collection is scoped by the record's scalar `learnerRef` ==
	 * that UUID, field-projected to hide staff-only columns. The learner may create their own Submission and
	 * ExcuseRequest (strict field whitelists — grades, status, staff decision
	 * and assurance fields stay server-authoritative). The GradeNotification
	 * inbox is scoped to the learner. `scopeClaim` names the subject claim
	 * portaliq resolves the scope value from (the stable claim contract).
	 *
	 * @return array<string, mixed> The student manifest.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function studentContribution(): array {
		$site = new StudentPortalPages();
		$own  = new StudentPortalCollections();
		$collections = array_merge(
			$own->studentResultCollections(),
			$own->studentActivityCollections(site: $site),
			[$own->studentTestsCollection(), $site->homeworkCollection(), $site->attendanceSummaryCollection(), $site->sessionsCollection()]
		);
		$actions = array_merge(
			$this->studentActions(site: $site),
			$this->studentTestActions(),
			[$this->handInAction()],
			(new CatalogueFlowActions())->actions(),
			(new WorkGroupFlowActions())->actions(),
			(new StudentFlowActions())->actions()
		);

		return [
			'label' => 'Learniq',
			'collections' => $collections,
			'actions' => $actions,
			// The overview and a short menu (site-pupil-portal-design).
			'pages' => $site->pages(collections: $collections, actions: $actions),
			'notifications' => [],
		];

	}//end studentContribution()

	/**
	 * The five steps of the timed task, each a server-to-server forward to
	 * PortalAssessmentController.
	 *
	 * Every action is a POST to an instance-local endpoint, whitelists only the
	 * fields its step sends, and has portaliq stamp the learner's own
	 * `learnerRef` into the body (`subjectField`) over any client value. The
	 * endpoints take the learner from that stamp alone and enforce every rule.
	 *
	 * @return array<int, array<string, mixed>> The timed-task actions.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-takes-a-timed-test-through-the-portal-req-pcon-008
	 */
	private function studentTestActions(): array {
		$steps = [
			'listTests' => ['', 'Tests you can take', []],
			'startTest' => ['/start', 'Start a test', ['taskId', 'accessCode']],
			'saveTestAnswer' => ['/answer', 'Save an answer', ['attemptId', 'itemId', 'response']],
			'submitTest' => ['/submit', 'Hand in a test', ['attemptId']],
			'readTestResult' => ['/result', 'View a result', ['attemptId']],
		];

		$actions = [];
		foreach ($steps as $id => [$path, $label, $fields]) {
			$actions[] = [
				'id' => $id,
				'type' => 'endpoint-forward',
				'label' => $label,
				'endpoint' => '/apps/learniq/api/portal/assessments' . $path,
				'method' => 'POST',
				'minTrust' => 'low',
				'fields' => $fields,
				'subjectField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
			];
		}

		return $actions;

	}//end studentTestActions()

	/**
	 * Hand in a draft submission: a server-to-server forward to
	 * PortalSubmissionController, which runs `submit` or `submitLate` as the
	 * pupil through SubmissionWindowGuard.
	 *
	 * Portaliq stamps the pupil's own `learnerRef` (`subjectField`) over any
	 * client value. `rowField` and `rowWhen` make it a row action where
	 * portaliq supports them (ConductionNL/portaliq#805): a button on the
	 * pupil's draft rows only, with `submissionId` stamped from the row portaliq
	 * read under the pupil's scope. Older portaliq ignores both keys and
	 * forwards `submissionId` from the body; the endpoint checks ownership
	 * either way.
	 *
	 * @return array<string, mixed> The hand-in action.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-a-draft-submission-from-the-portal-req-pcon-009
	 */
	private function handInAction(): array {
		return [
			'id' => 'handIn',
			'type' => 'endpoint-forward',
			'label' => 'Hand in',
			'endpoint' => '/apps/learniq/api/portal/submissions/hand-in',
			'method' => 'POST',
			'minTrust' => 'low',
			'fields' => ['submissionId'],
			'subjectField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'rowField' => 'submissionId',
			'rowWhen' => [
				'field' => 'lifecycle',
				'in' => ['draft'],
			],
		];

	}//end handInAction()

	/**
	 * The learner's own create-actions — hand in an assignment, report an absence.
	 *
	 * Strict field whitelists: grades, status, staff decision and assurance
	 * fields stay server-authoritative and are never client-writable.
	 *
	 * The hand-in carries real files through portaliq's file field
	 * (ConductionNL/portaliq#745): portaliq creates the Submission, uploads each
	 * file into its folder and appends the file id to `attachmentRefs`. The
	 * learners and tenant a portal create cannot send are stamped by
	 * `SubmissionOwnerStamp` from the pupil's LearnerProfile.
	 *
	 * @param StudentPortalPages $site The pupil's own declarations.
	 *
	 * @return array<int, array<string, mixed>> Student create-actions.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-work-through-the-portal-with-a-real-file-req-pcon-007
	 */
	private function studentActions(StudentPortalPages $site): array {
		return [
			$this->submissionAction(),
			// Between them, in the order her pages read (internship-hours).
			$site->hourWeekAction(),
			// "Nu invullen" on her work processes (guardian-tasks-per-child-and-self-assessment).
			(new StudentSelfAssessment())->action(),
			$this->absenceAction(),
		];

	}//end studentActions()


	/**
	 * She hands in a piece of work.
	 *
	 * @return array<string, mixed> The create action.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function submissionAction(): array {
		return [
			'id' => 'createSubmission',
			'type' => 'create',
			'label' => 'Hand in an assignment',
			'register' => self::REGISTER,
			'schema' => 'submission',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'minTrust' => 'low',
			'fields' => [
				'assignmentId',
				'attachmentRefs',
			],
			'fieldConfigs' => [
				// Same reason as the absence form below: an unlabelled field
				// is drawn as `assignmentId`.
				'assignmentId' => ['label' => 'The work you are handing in', 'required' => true],
				'attachmentRefs' => [
					'type' => 'file',
					'label' => 'Your work',
					'multiple' => true,
					'accept' => ['.pdf', '.doc', '.docx', '.odt', '.pptx', '.jpg', '.png'],
					'maxSizeMb' => 20,
				],
			],
			'submitLabel' => 'Hand in your work',
		];

	}//end submissionAction()

	/**
	 * She reports herself absent, with the same widgets her guardian gets.
	 *
	 * @return array<string, mixed> The create action.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function absenceAction(): array {
		return [
		'id' => 'createExcuseRequest',
		'type' => 'create',
		'label' => 'Report an absence',
		'register' => self::REGISTER,
		'schema' => 'excuse-request',
		'scopeField' => 'learnerRef',
		'scopeClaim' => 'learnerRef',
		'minTrust' => 'low',
		'fields' => [
			'dateFrom',
			'dateTo',
			'reason',
			'reasonKind',
			'attachmentRef',
		],
		// A field portaliq is given no label for is drawn under its own
		// name, so the pupil's form read `dateFrom`, `reason`,
		// `reasonKind` where her guardian's reads Dutch sentences
		// (measured on a live instance, pupil-flows.spec.ts). She gets
		// the same labels and the same widgets, addressed to her.
		'fieldConfigs' => [
			'dateFrom' => ['label' => 'First day you are absent', 'required' => true, 'widget' => 'dateChoices', 'dateChoices' => 2],
			'dateTo' => [
				'label' => 'Last day you are absent',
				'required' => true,
				'widget' => 'dateChoices',
				'dateChoices' => 2,
				'requiredMessage' => 'Choose the last day you are absent.',
			],
			'reason' => ['label' => 'Reason', 'required' => true],
			'reasonKind' => [
				'label' => 'Kind of absence',
				'required' => true,
				'valueLabels' => PortalValueLabels::ABSENCE_KIND,
				'widget' => 'choices',
				'choiceOptions' => ['illness', 'medical-appointment'],
				'otherLabel' => 'Another reason',
			],
			'attachmentRef' => (new ExcuseAttachmentField())->config(),
		],
		'submitLabel' => 'Report your absence',
		'successMessage' => 'The school has your report. You see the decision in the list of absence reports.',
		];

	}//end absenceAction()


	/**
	 * Manifest for the `parent` audience (a guardian of the learner).
	 *
	 * `subject.subjectRef` is a guardian domain-object UUID (claim
	 * `guardianRef`). A guardian has no direct scope key on the record schemas,
	 * so every read collection routes through portaliq's reverse / scope-value
	 * `via` join (contract v2.2, ADR-046 A5 ext):
	 *
	 * 1. The reader resolves the guardian's children — `learner-profile` rows
	 *    whose `guardianRefs` (an array) contains the guardian UUID — and, per
	 *    verified join row, collects the value at `via.targetField` into the
	 *    target set.
	 * 2. `via.targetField` is `id`: a normalised OpenRegister row exposes its
	 *    OWN object UUID at the top-level `id` key
	 *    (`ObjectEntity::jsonSerialize()` sets `$object['id'] = $this->uuid`),
	 *    which is exactly what `grade-entry.learnerRef` (a LearnerProfile
	 *    object UUID) points at. So the target set is the guardian's children's
	 *    LearnerProfile UUIDs.
	 * 3. `via.match` is `scopeField` (the REVERSE mode): each outer record
	 *    survives iff the value at its own `scopeField` (`learnerRef`) is in
	 *    that set — a foreign scope key, not the row's own id (the forward
	 *    default). An empty child set can only ever yield zero rows, never all
	 *    (fail-closed floor).
	 *
	 * Reads are `minTrust: substantial` — a guardian authenticating to a
	 * MINOR's grades/attendance/excuses needs substantial assurance (pairs with
	 * the DigiD/eHerkenning broker). The create-action stamps the guardian UUID
	 * into `submittedByRef` (never `learnerRef`, which is the child) and is
	 * likewise `substantial`. Field projection is identical to the student
	 * surface (same staff-only columns dropped). Reverse-join semantics, the
	 * minTrust story and a worked example: openspec/changes/archive/2026-09-28-portal-parent/design.md.
	 *
	 * @return array<string, mixed> The parent manifest.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function parentContribution(): array {
		// The one-hop reverse join shared by every parent read collection:
		// guardianRefs (array, on learner-profile) contains the guardian UUID →
		// collect each child profile's own object UUID (`id`) → keep outer rows
		// whose `learnerRef` is in that set (match: 'scopeField').
		$childJoin = [
			'register' => self::REGISTER,
			'schema' => 'learner-profile',
			'scopeField' => 'guardianRefs',
			'targetField' => 'id',
			'match' => 'scopeField',
		];
		$extras = new ParentPortalCollections();
		$record = new ParentRecordPage();
		$collections = array_merge(
			[$record->childrenCollection()],
			$this->parentResultCollections(childJoin: $childJoin),
			[$extras->reportCardGradesCollection(childJoin: $childJoin)],
			$this->parentWelfareCollections(childJoin: $childJoin),
			$extras->conferenceCollections(childJoin: $childJoin),
			[$extras->groupMembershipsCollection(childJoin: $childJoin)],
			[$extras->reportSubjectGradesCollection(childJoin: $childJoin)],
			$record->collections(childJoin: $childJoin)
		);
		$actions = array_merge(
			$this->parentActions(childJoin: $childJoin),
			$extras->conferenceActions()
		);

		return [
			// A parent reads "School" over these sections, not the app's name.
			'label' => 'School',
			'collections' => $collections,
			// One page per child, the calendar, then every other section.
			'pages' => $record->pages(collections: $collections, actions: $actions, sections: $extras),
			// Cross-references are checked against the guardian's children (portaliq#607).
			'actions' => $actions,
			// The guardian hears when the teacher answers a conference booking.
			'notifications' => [$extras->conferenceAnsweredRule()],
			// Portaliq news-audience-from-the-school-app: which of the collections
			// above name the guardian's children, their school and their groups,
			// so a news item for the school or a group reaches the guardian.
			'guardianAudience' => [
				'children' => 'parentChildren',
				'schoolField' => 'schoolId',
				'groups' => [
					'collection' => 'parentGroupMemberships',
					'field' => 'cohortId',
				],
			],
		];

	}//end parentContribution()

	/**
	 * The guardian's create-actions — report a child's absence.
	 *
	 * Mirrors `studentActions()`'s `createExcuseRequest`, scoped through the
	 * same reverse `via` join every parent read collection uses. The
	 * guardian's create body supplies `learnerRef` (which child); portaliq's
	 * writer (portaliq#607) validates that value resolves inside the
	 * `via`-derived scope before the write is accepted. `scopeField` is
	 * `submittedByRef` — never `learnerRef` — so the writer stamps the
	 * guardian's own UUID into the field that identifies WHO filed the
	 * excuse, not the field that identifies the child.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<int, array<string, mixed>> Parent create-actions.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
	 */
	private function parentActions(array $childJoin): array {
		return [
			[
				'id' => 'createExcuseRequest',
				'type' => 'create',
				'label' => "Report a child's absence",
				'register' => self::REGISTER,
				'schema' => 'excuse-request',
				'scopeField' => 'submittedByRef',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'minTrust' => 'substantial',
				'fields' => [
					'learnerRef',
					'dateFrom',
					'dateTo',
					'reason',
					'reasonKind',
					'attachmentRef',
				],
				// The child is picked from the guardian's own children, and portaliq
				// refuses any other value before the write (portal-parent-conference-
				// booking); ExcuseRequestOwnerStamp checks it again.
				'crossRefs' => ['learnerRef' => (new ParentPortalCollections())->childCrossRef()],
				'optionsProviders' => ['learnerRef' => (new ParentPortalCollections())->childOptions()],
				'fieldConfigs' => [
					'learnerRef' => ['label' => 'Child', 'required' => true],
					// Today and the next day as cards, then "Een andere dag" (portaliq
					// site-multi-step-forms REQ-SMF-005, LearniqAbsence.dc.html).
					'dateFrom' => ['label' => 'First day absent', 'required' => true, 'widget' => 'dateChoices', 'dateChoices' => 2],
					'dateTo' => [
						'label' => 'Last day absent',
						'required' => true,
						'widget' => 'dateChoices',
						'dateChoices' => 2,
						'requiredMessage' => 'Choose the last day your child is absent.',
					],
					'reason' => ['label' => 'Reason', 'required' => true],
					// Two cards and "Een andere reden" for the other four kinds, as the
					// approved mockup shows.
					'reasonKind' => [
						'label' => 'Kind of absence',
						'required' => true,
						'valueLabels' => PortalValueLabels::ABSENCE_KIND,
						'widget' => 'choices',
						'choiceOptions' => ['illness', 'medical-appointment'],
						'otherLabel' => 'Another reason',
					],
					'attachmentRef' => (new ExcuseAttachmentField())->config(),
				],
				'submitLabel' => 'Report the absence',
				'successMessage' => "The school has your report. You see the teacher's decision in the list of absence reports.",
				// The sentence above the send button and on the confirmation (board MobielDetail:
				// "Sami is vandaag de hele dag ziek."). A date answer reads as "vandaag", "morgen" or
				// a weekday in the page language; the child reads as the option's own label (L2-3).
				'summary' => [
					'label' => 'You report',
					'template' => '{learnerRef} is {reasonKind} {dateFrom}.',
					'phrases' => [
						'reasonKind' => [
							'illness' => 'ill',
							'medical-appointment' => 'at the doctor or dentist',
							'family-circumstance' => 'away for a family reason',
							'religious-observance' => 'away for a religious holiday',
							'bereavement' => 'away for a funeral',
							'other' => 'away for another reason',
						],
					],
				],
				// What she reads after sending (site-guardian-portal-design T6b, REQ-SMF-022).
				'confirmation' => [
					'title' => 'Your report has been sent',
					'body' => 'The teacher sees it in the class right away. In the list of absence reports you see when the teacher has decided.',
				],
			],
		];

	}//end parentActions()

	/**
	 * The guardian's result collections — the child's grades and attendance.
	 *
	 * Both route through the one-hop reverse `via` join so a guardian can only
	 * ever see rows whose `learnerRef` is one of their own children's
	 * LearnerProfile UUIDs, and both are `minTrust: substantial`.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<int, array<string, mixed>> Parent result collections.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function parentResultCollections(array $childJoin): array {
		return [
			[
				'id' => 'parentGrades',
				'register' => self::REGISTER,
				'schema' => 'grade-entry',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'groupByField' => 'learnerRef',
				'label' => "My child's grades",
				'listable' => true,
				'minTrust' => 'substantial',
				'fields' => [
					'learnerRef',
					'courseId',
					// Readable copies and the weight (site-guardian-portal-design):
					// the subject and test a grade is for, and how often it counts.
					'courseName',
					'methodName',
					'methodBlock',
					'weight',
					'curriculumPlanId',
					'componentId',
					'value',
					'gradeScaleId',
					'period',
					'gradedAt',
				],
				'columns' => [
					['field' => 'value', 'label' => 'Grade'],
					['field' => 'period', 'label' => 'Period'],
					['field' => 'gradedAt', 'label' => 'Given on'],
				],
			],
			[
				'id' => 'parentAttendance',
				'register' => self::REGISTER,
				'schema' => 'attendance-record',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'groupByField' => 'learnerRef',
				'label' => "My child's attendance",
				'listable' => true,
				'minTrust' => 'substantial',
				'fields' => [
					'learnerRef',
					'sessionId',
					'cohortId',
					'status',
					'minutesAttended',
					'markedAt',
				],
				'columns' => [
					['field' => 'markedAt', 'label' => 'Date'],
					['field' => 'status', 'label' => 'Attendance', 'valueLabels' => PortalValueLabels::ATTENDANCE_STATUS],
					['field' => 'minutesAttended', 'label' => 'Minutes present'],
				],
			],
		];

	}//end parentResultCollections()

	/**
	 * The guardian's welfare collections — the child's absence excuses and report cards.
	 *
	 * Both route through the same one-hop reverse `via` join and are
	 * `minTrust: substantial`. `parentReportCards` additionally carries a
	 * server-side singular `filter` so only a `published-to-parents` ReportCard
	 * is ever exposed — never one still under internal review.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<int, array<string, mixed>> Parent welfare collections.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function parentWelfareCollections(array $childJoin): array {
		return [
			[
				'id' => 'parentExcuseRequests',
				'register' => self::REGISTER,
				'schema' => 'excuse-request',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'groupByField' => 'learnerRef',
				// The newest absence first: a guardian looks for the report
				// they just sent, not for the oldest one on file.
				'defaultSort' => ['field' => 'dateFrom', 'direction' => 'desc'],
				'label' => "My child's absence excuses",
				'listable' => true,
				'minTrust' => 'substantial',
				'fields' => [
					'learnerRef',
					'dateFrom',
					'dateTo',
					'reason',
					'reasonKind',
					'attachmentRef',
					'lifecycle',
					'decidedAt',
					// Read only as a name (render: user), never as the user id.
					'decidedBy',
				],
				'columns' => [
					['field' => 'dateFrom', 'label' => 'From'],
					['field' => 'dateTo', 'label' => 'To'],
					['field' => 'reason', 'label' => 'Reason'],
					['field' => 'lifecycle', 'label' => 'Status', 'valueLabels' => PortalValueLabels::EXCUSE_STATUS],
					['field' => 'decidedAt', 'label' => 'Decided on'],
					['field' => 'decidedBy', 'label' => 'Decided by', 'render' => 'user'],
				],
			],
			[
				'id' => 'parentReportCards',
				'register' => self::REGISTER,
				'schema' => 'report-card',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'guardianRef',
				'via' => $childJoin,
				'groupByField' => 'learnerRef',
				'label' => "My child's report cards",
				'listable' => true,
				'minTrust' => 'substantial',
				// Server-side lifecycle filter, mirroring report-card's own
				// "never draft/rapportvergadering-review/finalised" requirement —
				// a guardian must only ever see a published-to-parents ReportCard,
				// never one still under internal review. Key is singular `filter`
				// (ContributionController::collection() reads $collection['filter'],
				// applied by PortalObjectReader::readCollection() BEFORE the scope
				// filter, so it can only ever subset the guardian's own rows —
				// mirrors pipelinq's PortalContributionProvider's own `filter` usage).
				'filter' => ['lifecycle' => 'published-to-parents'],
				// The readable copies (periodName, gradeLines) stand in for the
				// nested subjectGrades: the portal writes a nested list into one
				// cell and leaves its uuids out, so it read "7,9, Yes" without a
				// subject or a period (portal-parent-report-card-grades).
				'fields' => [
					'learnerRef',
					'reportPeriodId',
					'periodName',
					'gradeLines',
					'attendanceSummary',
					'mentorComment',
					'docudeskDocumentRef',
				],
				'columns' => [
					['field' => 'periodName', 'label' => 'Period'],
					['field' => 'mentorComment', 'label' => "Teacher's comment"],
					['field' => 'gradeLines', 'label' => 'Grades'],
				],
			],
		];

	}//end parentWelfareCollections()


}//end class
