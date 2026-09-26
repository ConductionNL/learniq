<?php

/**
 * Unit tests for the Learniq PortalContributionProvider.
 *
 * Pins the ADR-046 contribution contract v2: the dual v2/v1 audience
 * declaration, the fail-closed null for unserved audiences, and the exact
 * declarative manifest shape for the `student` and `parent` audiences
 * (UUID-domain-ref-scoped collections + inbox + strict create whitelists). The
 * provider is constructed directly — it is a plain dependency-free class by
 * contract (amendment A1), so no mocks and no container are involved.
 *
 * A register-drift pin (testManifestMatchesRegisterSchemas) loads the shipped
 * `learniq_register.json` and asserts every schema slug, scope field,
 * whitelisted field AND parent `via` scope field the manifest references
 * actually exists — so a rename in the register (or a missing `portal-identity`
 * ref) fails this test instead of silently breaking the portal at runtime. The
 * `parent` reverse-join collections are covered now that portaliq ships the
 * reverse / scope-value `via` join (`match: 'scopeField'`).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalContributionProvider.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
class PortalContributionProviderTest extends TestCase {

	/**
	 * The provider under test.
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * A fully server-derived student subject, as portaliq's auth edge builds it.
	 *
	 * @var array<string, mixed>
	 */
	private const STUDENT_SUBJECT = [
		'subjectRef' => '11111111-1111-1111-1111-111111111111',
		'audience' => 'student',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'low',
	];

	/**
	 * A fully server-derived parent (guardian) subject.
	 *
	 * @var array<string, mixed>
	 */
	private const PARENT_SUBJECT = [
		'subjectRef' => '22222222-2222-2222-2222-222222222222',
		'audience' => 'parent',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'substantial',
	];

	/**
	 * A fully server-derived praktijkopleider (workplace supervisor) subject.
	 *
	 * @var array<string, mixed>
	 */
	private const PRAKTIJKOPLEIDER_SUBJECT = [
		'subjectRef' => '33333333-3333-3333-3333-333333333333',
		'audience' => 'praktijkopleider',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'substantial',
	];

	/**
	 * A fully server-derived external-assessor (eportfolio) subject.
	 *
	 * @var array<string, mixed>
	 */
	private const EXTERNAL_ASSESSOR_SUBJECT = [
		'subjectRef' => '44444444-4444-4444-4444-444444444444',
		'audience' => 'external-assessor',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'low',
	];

	/**
	 * Set up the provider — direct construction, no dependencies by contract.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();

	}//end setUp()

	/**
	 * The class is plain: no interfaces, no parent, no constructor deps.
	 *
	 * @return void
	 */
	public function testClassIsPlainAndDependencyFree(): void {
		$reflection = new \ReflectionClass(PortalContributionProvider::class);

		$this->assertSame([], $reflection->getInterfaceNames());
		$this->assertFalse($reflection->getParentClass());
		$this->assertNull($reflection->getConstructor());

	}//end testClassIsPlainAndDependencyFree()

	/**
	 * getAudiences() (v2) returns exactly ['student','parent','praktijkopleider',
	 * 'external-assessor'] and getAudience() (v1 fallback) is one of them. The `parent`
	 * audience is re-enabled now that portaliq ships the reverse / scope-value `via` join
	 * (match: 'scopeField'); `praktijkopleider` is the bpv-praktijkovereenkomst change's third
	 * audience; `external-assessor` is the eportfolio change's fourth audience.
	 *
	 * @return void
	 */
	public function testAudienceContract(): void {
		$this->assertSame(
			['student', 'parent', 'praktijkopleider', 'external-assessor'],
			$this->provider->getAudiences()
		);
		$this->assertSame('student', $this->provider->getAudience());
		$this->assertContains($this->provider->getAudience(), $this->provider->getAudiences());

	}//end testAudienceContract()

	/**
	 * Unserved / absent audiences get null — fail-closed audience filtering.
	 * `student`, `parent`, `praktijkopleider` and `external-assessor` are served; everything
	 * else (and an empty subject) is null.
	 *
	 * @return void
	 */
	public function testGetContributionReturnsNullForUnservedSubjects(): void {
		$teacher = self::STUDENT_SUBJECT;
		$teacher['audience'] = 'teacher';

		$this->assertNull($this->provider->getContribution($teacher));
		$this->assertNull($this->provider->getContribution([]));

		// `parent`, `praktijkopleider` and `external-assessor` are served audiences — they
		// return a manifest, not null.
		$this->assertIsArray($this->provider->getContribution(self::PARENT_SUBJECT));
		$this->assertIsArray($this->provider->getContribution(self::PRAKTIJKOPLEIDER_SUBJECT));
		$this->assertIsArray($this->provider->getContribution(self::EXTERNAL_ASSESSOR_SUBJECT));

	}//end testGetContributionReturnsNullForUnservedSubjects()

	/**
	 * The student manifest is labelled and carries all four sections, with the
	 * six learner-scoped read collections plus the inbox.
	 *
	 * @return void
	 */
	public function testStudentManifestShape(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Learniq', $manifest['label']);
		$this->assertSame([], $manifest['notifications']);

		$collections = $manifest['collections'];
		$this->assertCount(7, $collections);
		$this->assertSame(
			[
				'studentGrades',
				'studentFinalGrades',
				'studentAttendance',
				'studentEnrolments',
				'studentSubmissions',
				'studentExcuseRequests',
				'studentInbox',
			],
			array_column($collections, 'id')
		);

		foreach ($collections as $collection) {
			$this->assertSame('learniq', $collection['register']);
			$this->assertSame('learnerRef', $collection['scopeClaim']);
			$this->assertNotEmpty($collection['fields']);
			// Submission is scoped by the learnerRefs ARRAY (membership); every
			// other collection is scoped by the scalar learnerRef.
			if ($collection['schema'] === 'submission') {
				$this->assertSame('learnerRefs', $collection['scopeField']);
			} else {
				$this->assertSame('learnerRef', $collection['scopeField']);
			}
		}

	}//end testStudentManifestShape()

	/**
	 * The student inbox is a `kind: inbox` collection scoped by learnerRef.
	 *
	 * @return void
	 */
	public function testStudentInboxIsScopedInbox(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
		$inbox = array_values(
			array_filter(
				$manifest['collections'],
				static fn (array $c): bool => ($c['id'] ?? '') === 'studentInbox'
			)
		)[0];

		$this->assertSame('inbox', $inbox['kind']);
		$this->assertSame('grade-notification', $inbox['schema']);
		$this->assertSame('learnerRef', $inbox['scopeField']);

	}//end testStudentInboxIsScopedInbox()

	/**
	 * Student create-actions whitelist intake fields only — no grade, status,
	 * lifecycle or staff field is exposed.
	 *
	 * @return void
	 */
	public function testStudentCreateActionsWhitelistIntakeFields(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
		$actions = $manifest['actions'];

		$this->assertSame(['createSubmission', 'createExcuseRequest'], array_column($actions, 'id'));

		$submission = $actions[0];
		$this->assertSame('create', $submission['type']);
		$this->assertSame('submission', $submission['schema']);
		$this->assertSame('learnerRefs', $submission['scopeField']);
		$this->assertSame(['assignmentId', 'attachmentRefs'], $submission['fields']);

		$excuse = $actions[1];
		$this->assertSame('create', $excuse['type']);
		$this->assertSame('excuse-request', $excuse['schema']);
		$this->assertSame('learnerRef', $excuse['scopeField']);
		$this->assertSame('low', $excuse['minTrust']);
		$this->assertSame(
			['dateFrom', 'dateTo', 'reason', 'reasonKind', 'attachmentRef'],
			$excuse['fields']
		);
		// A student create never lets the client set grade/status/staff fields.
		foreach (['value', 'passed', 'lifecycle', 'submittedBy', 'submittedAuthLevel', 'decidedBy'] as $forbidden) {
			$this->assertNotContains($forbidden, $excuse['fields']);
			$this->assertNotContains($forbidden, $submission['fields']);
		}

	}//end testStudentCreateActionsWhitelistIntakeFields()

	/**
	 * The parent manifest is labelled and carries exactly the three
	 * reverse-joined read collections (grades, attendance, excuse-requests),
	 * each guardian-claimed, learnerRef-scoped and substantial-trust,
	 * field-projected identically to the student surface.
	 *
	 * @return void
	 */
	public function testParentManifestShape(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Learniq', $manifest['label']);
		$this->assertSame([], $manifest['notifications']);

		$collections = $manifest['collections'];
		$this->assertCount(5, $collections);
		$this->assertSame(
			['parentChildren', 'parentGrades', 'parentAttendance', 'parentExcuseRequests', 'parentReportCards'],
			array_column($collections, 'id')
		);

		$byId = array_column($collections, null, 'id');

		// parentChildren is a direct match (no via — see
		// testParentChildrenCollectionMatchesDirectly), so it is excluded from
		// this reverse-join-shaped assertion loop.
		$reverseJoinedCollections = array_filter($collections, static fn ($c) => $c['id'] !== 'parentChildren');
		foreach ($reverseJoinedCollections as $collection) {
			$this->assertSame('learniq', $collection['register']);
			// Parent scope key is the guardian claim; the outer record scope
			// field is the child's learnerRef (matched by the reverse via).
			$this->assertSame('guardianRef', $collection['scopeClaim']);
			$this->assertSame('learnerRef', $collection['scopeField']);
			// A guardian reading a MINOR's data needs substantial assurance.
			$this->assertSame('substantial', $collection['minTrust']);
			$this->assertNotEmpty($collection['fields']);
			// Portal-contribution-guardian-audiences: a portal groups these
			// per child without a schema change.
			$this->assertSame('learnerRef', $collection['groupByField']);
			// Parent reads never expose staff-only columns (same drop as student).
			foreach (['grader', 'comment', 'markedBy', 'submittedBy', 'submittedByRef', 'decidedBy', 'decisionNote'] as $forbidden) {
				$this->assertNotContains($forbidden, $collection['fields']);
			}
		}

		// Parent grade/attendance/excuse projections mirror the student ones.
		$this->assertSame(
			['learnerRef', 'courseId', 'curriculumPlanId', 'componentId', 'value', 'gradeScaleId', 'period', 'gradedAt'],
			$byId['parentGrades']['fields']
		);
		$this->assertSame(
			['learnerRef', 'sessionId', 'cohortId', 'status', 'minutesAttended', 'markedAt'],
			$byId['parentAttendance']['fields']
		);
		$this->assertSame(
			['learnerRef', 'dateFrom', 'dateTo', 'reason', 'reasonKind', 'attachmentRef', 'lifecycle', 'decidedAt'],
			$byId['parentExcuseRequests']['fields']
		);
		$this->assertSame(
			['learnerRef', 'reportPeriodId', 'subjectGrades', 'attendanceSummary', 'mentorComment', 'docudeskDocumentRef'],
			$byId['parentReportCards']['fields']
		);

		// parentReportCards is server-side narrowed to published-to-parents only
		// (report-card-composer's own "never draft/rapportvergadering-review/
		// finalised" requirement) — the reader's singular `filter` key, applied
		// BEFORE the scope filter (ContributionController::collection()).
		$this->assertSame(['lifecycle' => 'published-to-parents'], $byId['parentReportCards']['filter']);

	}//end testParentManifestShape()

	/**
	 * parentChildren matches `learner-profile` DIRECTLY — `guardianRefs`
	 * (array, on the schema being read) containing the guardian's own
	 * subjectRef — the same array-containment match
	 * `testStudentManifestShape()`'s `studentSubmissions` (`learnerRefs`)
	 * already exercises. It carries NO `via` (no cross-object hop is
	 * needed), and exposes the full co-guardian group plus current
	 * beeldmateriaal consent state.
	 *
	 * @return void
	 * @spec openspec/changes/portal-contribution-guardian-audiences/specs/portal-contribution/spec.md#requirement-the-parent-audience-exposes-per-child-and-per-guardian-group-directory-data-req-pcon-006
	 */
	public function testParentChildrenCollectionMatchesDirectly(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);
		$byId = array_column($manifest['collections'], null, 'id');
		$children = $byId['parentChildren'] ?? null;

		$this->assertIsArray($children, 'parentChildren collection MUST exist');
		$this->assertArrayNotHasKey('via', $children, 'parentChildren MUST NOT declare a via join — no cross-object hop is needed');
		$this->assertSame('learniq', $children['register']);
		$this->assertSame('learner-profile', $children['schema']);
		$this->assertSame('guardianRefs', $children['scopeField']);
		$this->assertSame('guardianRef', $children['scopeClaim']);
		$this->assertSame('substantial', $children['minTrust']);
		$this->assertSame(
			['givenName', 'familyName', 'guardianRefs', 'beeldmateriaalConsent', 'beeldmateriaalConsentReviewDueAt'],
			$children['fields']
		);

	}//end testParentChildrenCollectionMatchesDirectly()

	/**
	 * Every parent read collection carries the reverse / scope-value `via` join
	 * with EXACTLY the reader's contract keys — `{register, schema, scopeField,
	 * targetField, match}` — and `match: 'scopeField'`. The join resolves the
	 * guardian's children through `learner-profile.guardianRefs` and collects
	 * each child profile's own OR object UUID (`id`), which the outer records
	 * match on their own `learnerRef`. Invented keys (`matchField`/`selectField`)
	 * would fail portaliq's `isValidVia()` fail-closed — so pin the exact set.
	 *
	 * @return void
	 */
	public function testParentCollectionsUseReverseScopeValueVia(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

		// parentChildren is deliberately excluded — it matches learner-profile
		// directly (see testParentChildrenCollectionMatchesDirectly), the one
		// parent collection with no cross-object hop and therefore no via.
		$reverseJoinedCollections = array_filter(
			$manifest['collections'],
			static fn ($c) => $c['id'] !== 'parentChildren'
		);

		foreach ($reverseJoinedCollections as $collection) {
			$via = $collection['via'] ?? null;
			$this->assertIsArray($via, "parent collection '{$collection['id']}' must declare a via join");

			// The reader (PortalObjectReader::isValidVia) recognises EXACTLY these
			// keys; anything else (matchField/selectField) is ignored/fails closed.
			$this->assertSame(
				['register', 'schema', 'scopeField', 'targetField', 'match'],
				array_keys($via),
				"via keys must be exactly the reader's contract for '{$collection['id']}'"
			);

			$this->assertSame('learniq', $via['register']);
			$this->assertSame('learner-profile', $via['schema']);
			// The join row's field matched against the guardian scope value.
			$this->assertSame('guardianRefs', $via['scopeField']);
			// The child LearnerProfile's own object UUID — a normalised OR row
			// exposes it at top-level `id` (ObjectEntity::jsonSerialize sets
			// $object['id'] = $this->uuid), which is what learnerRef points at.
			$this->assertSame('id', $via['targetField']);
			// Reverse mode: keep outer rows whose OWN scopeField is in the set.
			$this->assertSame('scopeField', $via['match']);

			// The outer collection's own scope field the reverse match reads.
			$this->assertSame('learnerRef', $collection['scopeField']);
		}

	}//end testParentCollectionsUseReverseScopeValueVia()

	/**
	 * portal-contribution-guardian-audiences: the parent audience now ships
	 * `createExcuseRequest`, now that portaliq's writer cross-reference guard
	 * (portaliq#607, merged 2026-09-18) validates a client-supplied
	 * cross-reference against the subject's own `via`-derived scope. The
	 * load-bearing assertion is `scopeField`: it MUST be `submittedByRef`
	 * (who filed it), never `learnerRef` (which child it concerns) — stamping
	 * `learnerRef` from the guardian's own resolved UUID would silently write
	 * the guardian's UUID into the child-identifying field, the exact write
	 * IDOR shape this action was withheld to avoid before portaliq#607 landed.
	 * `via` MUST be byte-identical to the read collections' own reverse join
	 * (belt-and-braces per the lane's orchestrator instruction).
	 *
	 * @return void
	 * @spec openspec/changes/portal-contribution-guardian-audiences/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
	 */
	public function testParentShipsCreateExcuseRequestValidatedAgainstOwnChildren(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

		$this->assertCount(1, $manifest['actions']);
		$action = $manifest['actions'][0];

		$this->assertSame('createExcuseRequest', $action['id']);
		$this->assertSame('create', $action['type']);
		$this->assertSame('learniq', $action['register']);
		$this->assertSame('excuse-request', $action['schema']);
		$this->assertSame('submittedByRef', $action['scopeField'], 'scopeField MUST be submittedByRef, never learnerRef');
		$this->assertSame('guardianRef', $action['scopeClaim']);
		$this->assertSame('substantial', $action['minTrust']);
		$this->assertContains('learnerRef', $action['fields'], 'the guardian MUST supply which child the excuse concerns');

		// Drift pin: the create action's via MUST be the exact reverse-join
		// descriptor every parent read collection already uses — the same
		// scope the guardian's supplied learnerRef is validated against.
		$readCollectionVia = $manifest['collections'][1]['via'] ?? null;
		$this->assertIsArray($readCollectionVia, 'a reverse-joined read collection must exist to compare against');
		$this->assertSame($readCollectionVia, $action['via'], "the create action's via MUST match the read collections' via exactly");

	}//end testParentShipsCreateExcuseRequestValidatedAgainstOwnChildren()

	/**
	 * The praktijkopleider manifest carries a single direct-scoped BpvPlacement read
	 * collection (praktijkopleiderId == subjectRef), field-projected to drop
	 * schoolCoachId and leerbedrijfVerification.raw.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-praktijkopleider-portal-access-is-a-direct-scope-portalcontributionprovider-audience
	 */
	public function testPraktijkopleiderManifestShape(): void {
		$manifest = $this->provider->getContribution(self::PRAKTIJKOPLEIDER_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Learniq', $manifest['label']);
		$this->assertSame([], $manifest['notifications']);

		$collections = $manifest['collections'];
		$this->assertCount(2, $collections);
		$collection = $collections[0];

		$this->assertSame('poBpvPlacements', $collection['id']);
		$this->assertSame('learniq', $collection['register']);
		$this->assertSame('bpv-placement', $collection['schema']);
		// Direct match — not a reverse `via` join like `parent`.
		$this->assertSame('practicalTrainerId', $collection['scopeField']);
		$this->assertSame('practicalTrainerId', $collection['scopeClaim']);
		$this->assertArrayNotHasKey('via', $collection);
		$this->assertSame('low', $collection['minTrust']);

		foreach (['schoolCoachId', 'trainingCompanyVerification', 'leerbedrijfVerification.raw'] as $forbidden) {
			$this->assertNotContains($forbidden, $collection['fields']);
		}

	}//end testPraktijkopleiderManifestShape()

	/**
	 * eportfolio: the praktijkopleider audience gains exactly one new collection,
	 * `poSharedPortfolios` — direct-matched over `portfolio-share`
	 * (`sharedWithPraktijkopleiderId == subject.subjectRef`), mirroring `poBpvPlacements`'s
	 * shape, filtered to `lifecycle: active` so a revoked share resolves no rows. No change to
	 * the existing `poBpvPlacements` collection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
	 */
	public function testPraktijkopleiderGainsSharedPortfoliosCollection(): void {
		$manifest = $this->provider->getContribution(self::PRAKTIJKOPLEIDER_SUBJECT);
		$collection = $manifest['collections'][1];

		$this->assertSame('poSharedPortfolios', $collection['id']);
		$this->assertSame('learniq', $collection['register']);
		$this->assertSame('portfolio-share', $collection['schema']);
		$this->assertSame('sharedWithPracticalTrainerId', $collection['scopeField']);
		$this->assertSame('practicalTrainerId', $collection['scopeClaim']);
		$this->assertArrayNotHasKey('via', $collection);
		// A revoked share must resolve no rows.
		$this->assertSame(['lifecycle' => 'active'], $collection['filter']);
		$this->assertContains('portfolioId', $collection['fields']);
		$this->assertContains('entryIds', $collection['fields']);

	}//end testPraktijkopleiderGainsSharedPortfoliosCollection()

	/**
	 * eportfolio: `external-assessor` mirrors `poSharedPortfolios`'s shape exactly, scoped by
	 * `sharedWithExternalAssessorId` instead, and ships zero create-actions (read-only per the
	 * brief).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/eportfolio/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
	 */
	public function testExternalAssessorManifestShape(): void {
		$manifest = $this->provider->getContribution(self::EXTERNAL_ASSESSOR_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Learniq', $manifest['label']);
		$this->assertSame([], $manifest['notifications']);
		// Read-only — zero create-actions.
		$this->assertSame([], $manifest['actions']);

		$collections = $manifest['collections'];
		$this->assertCount(1, $collections);
		$collection = $collections[0];

		$this->assertSame('eaSharedPortfolios', $collection['id']);
		$this->assertSame('learniq', $collection['register']);
		$this->assertSame('portfolio-share', $collection['schema']);
		$this->assertSame('sharedWithExternalAssessorId', $collection['scopeField']);
		$this->assertSame('externalAssessorId', $collection['scopeClaim']);
		$this->assertArrayNotHasKey('via', $collection);
		// A revoked share must resolve no rows.
		$this->assertSame(['lifecycle' => 'active'], $collection['filter']);
		$this->assertContains('portfolioId', $collection['fields']);
		$this->assertContains('entryIds', $collection['fields']);

	}//end testExternalAssessorManifestShape()

	/**
	 * Both praktijkopleider create-actions are `type: create`, direct-scope-stamped from
	 * `subject.subjectRef` (never the request body), `minTrust: substantial`, and whitelist
	 * only placement/kwalificatiedossier/beoordeling/signature-evidence fields — never a
	 * staff decision or an already-published grade/status field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-praktijkopleider-portal-actions-never-trust-client-supplied-identity
	 */
	public function testPraktijkopleiderActionsAreDirectScopeStampedAndWhitelisted(): void {
		$manifest = $this->provider->getContribution(self::PRAKTIJKOPLEIDER_SUBJECT);
		$actions = $manifest['actions'];

		$this->assertSame(['createWerkprocesAssessment', 'signPraktijkovereenkomst'], array_column($actions, 'id'));

		$assessment = $actions[0];
		$this->assertSame('create', $assessment['type']);
		$this->assertSame('werkproces-assessment', $assessment['schema']);
		$this->assertSame('assessorId', $assessment['scopeField']);
		$this->assertSame('practicalTrainerId', $assessment['scopeClaim']);
		$this->assertSame('substantial', $assessment['minTrust']);
		$this->assertSame(
			[
				'bpvPlacementId',
				'curriculumPlanId',
				'componentId',
				'kwalificatiedossierCode',
				'coreTaskCode',
				'werkprocesCode',
				'werkprocesLabel',
				'assessment',
				'notes',
			],
			$assessment['fields']
		);

		$signature = $actions[1];
		$this->assertSame('create', $signature['type']);
		$this->assertSame('pok-signature', $signature['schema']);
		$this->assertSame('signerId', $signature['scopeField']);
		$this->assertSame('practicalTrainerId', $signature['scopeClaim']);
		$this->assertSame('substantial', $signature['minTrust']);
		$this->assertSame(
			['subjectId', 'subjectVersion', 'assuranceLevel', 'method', 'evidenceRef'],
			$signature['fields']
		);

		// Neither create lets the client set a staff/grade/status field.
		foreach (['assessorId', 'signerId', 'lifecycle', 'signedAt'] as $forbidden) {
			$this->assertNotContains($forbidden, $assessment['fields']);
			$this->assertNotContains($forbidden, $signature['fields']);
		}

	}//end testPraktijkopleiderActionsAreDirectScopeStampedAndWhitelisted()

	/**
	 * Register-drift pin: every schema slug, scope field, whitelisted field and
	 * `via` scope-field the manifest references MUST exist in the shipped
	 * learniq_register.json — proving the `portal-identity` refs are present and
	 * that no register rename silently broke the portal. Covers the parent
	 * reverse-join collections too (their via `scopeField` is `guardianRefs` on
	 * `learner-profile`; `targetField` is the OR object-identity token `id`, not
	 * a schema property, so it is checked against the identity tokens).
	 *
	 * @return void
	 */
	public function testManifestMatchesRegisterSchemas(): void {
		$registerPath = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->assertFileExists($registerPath);

		$register = json_decode((string)file_get_contents($registerPath), true);
		$this->assertIsArray($register);

		// Build slug => property-names map from the register.
		$propsBySlug = [];
		foreach (($register['components']['schemas'] ?? []) as $schema) {
			$slug = $schema['slug'] ?? null;
			if ($slug !== null) {
				$propsBySlug[$slug] = array_keys($schema['properties'] ?? []);
			}
		}

		// The portal-identity refs MUST exist (the change this provider depends on).
		$this->assertContains('learnerRef', $propsBySlug['grade-entry'] ?? []);
		$this->assertContains('learnerRefs', $propsBySlug['submission'] ?? []);
		$this->assertContains('submittedByRef', $propsBySlug['excuse-request'] ?? []);
		$this->assertContains('guardianRefs', $propsBySlug['learner-profile'] ?? []);

		// Portal-contribution-guardian-audiences: the parentChildren
		// collection's whitelisted fields.
		$this->assertContains('beeldmateriaalConsent', $propsBySlug['learner-profile'] ?? []);
		$this->assertContains('beeldmateriaalConsentReviewDueAt', $propsBySlug['learner-profile'] ?? []);

		// The bpv-praktijkovereenkomst refs the praktijkopleider audience depends on.
		$this->assertContains('practicalTrainerId', $propsBySlug['bpv-placement'] ?? []);
		$this->assertContains('assessorId', $propsBySlug['werkproces-assessment'] ?? []);
		$this->assertContains('signerId', $propsBySlug['pok-signature'] ?? []);

		// The eportfolio refs the poSharedPortfolios/eaSharedPortfolios collections depend on.
		$this->assertContains('sharedWithPracticalTrainerId', $propsBySlug['portfolio-share'] ?? []);
		$this->assertContains('sharedWithExternalAssessorId', $propsBySlug['portfolio-share'] ?? []);
		$this->assertContains('portfolioId', $propsBySlug['portfolio-share'] ?? []);
		$this->assertContains('entryIds', $propsBySlug['portfolio-share'] ?? []);

		// All four audiences are served; each yields a manifest.
		foreach (
			[
				self::STUDENT_SUBJECT,
				self::PARENT_SUBJECT,
				self::PRAKTIJKOPLEIDER_SUBJECT,
				self::EXTERNAL_ASSESSOR_SUBJECT,
			] as $subject
		) {
			$manifest = $this->provider->getContribution($subject);
			$this->assertIsArray($manifest);

			foreach (($manifest['collections'] ?? []) as $collection) {
				$slug = $collection['schema'];
				$this->assertArrayHasKey($slug, $propsBySlug, "manifest schema '$slug' missing from register");
				$props = $propsBySlug[$slug];

				$this->assertContains($collection['scopeField'], $props, "scopeField on '$slug' not in register");
				foreach (($collection['fields'] ?? []) as $field) {
					$this->assertContains($field, $props, "field '$field' on '$slug' not in register");
				}

				if (isset($collection['via']) === true) {
					$via = $collection['via'];
					$viaSlug = $via['schema'];
					$this->assertArrayHasKey($viaSlug, $propsBySlug, "via schema '$viaSlug' missing from register");
					// The via's join scope field (guardianRefs) MUST be a real
					// property on the via schema — this is the drift-detectable ref.
					$this->assertContains(
						$via['scopeField'],
						$propsBySlug[$viaSlug],
						"via scopeField '{$via['scopeField']}' not in register schema '$viaSlug'"
					);
					// The via's targetField is either a schema property OR the OR
					// object-identity token ('id'/'uuid') the normalised row
					// exposes — never an invented key.
					$this->assertContains(
						$via['targetField'],
						array_merge($propsBySlug[$viaSlug], ['id', 'uuid']),
						"via targetField '{$via['targetField']}' is neither a register property on '$viaSlug' nor an identity token"
					);
				}
			}

			foreach (($manifest['actions'] ?? []) as $action) {
				$slug = $action['schema'];
				$this->assertArrayHasKey($slug, $propsBySlug, "action schema '$slug' missing from register");
				$props = $propsBySlug[$slug];
				$this->assertContains($action['scopeField'], $props, "action scopeField on '$slug' not in register");
				foreach (($action['fields'] ?? []) as $field) {
					$this->assertContains($field, $props, "action field '$field' on '$slug' not in register");
				}
			}
		}

	}//end testManifestMatchesRegisterSchemas()
}//end class
