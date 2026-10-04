<?php

/**
 * Learniq ExcuseRequestOwnerStamp unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\ExcuseRequestOwnerStamp;
use OCA\Learniq\Service\Portal\PortalWriteSubject;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\PupilGroupTeachers;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use Opis\JsonSchema\Validator;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the server-side owner stamp on ExcuseRequest writes.
 */
class ExcuseRequestOwnerStampTest extends TestCase {

	private const TENANT = '11111111-1111-4111-8111-111111111111';

	/**
	 * Active LearnerProfiles by uuid, as LearnerRefResolver::byRef() returns them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $profiles = [];

	/**
	 * LearnerProfile uuid by Nextcloud user id.
	 *
	 * @var array<string, string>
	 */
	private array $refsByUser = [];

	/**
	 * The fake OpenRegister store the group-teacher lookup reads cohorts from.
	 *
	 * @var RegisterFaithfulStore|null
	 */
	private ?RegisterFaithfulStore $store = null;

	/**
	 * Build the stamp over in-memory profiles.
	 *
	 * @param string $schemaSlug The slug the resolver reports.
	 * @param bool $hasUser Whether a Nextcloud session is present.
	 * @param bool $lookupThrows Whether every lookup fails.
	 * @param bool $cohortsThrow Whether the cohort read fails.
	 *
	 * @return ExcuseRequestOwnerStamp
	 */
	private function makeStamp(
		string $schemaSlug = 'excuse-request',
		bool $hasUser = false,
		string $uid = 'mentor-1',
		bool $lookupThrows = false,
		bool $cohortsThrow = false,
	): ExcuseRequestOwnerStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		$lookup = $this->createMock(LearnerRefResolver::class);
		$lookup->method('byRef')->willReturnCallback(
			function (string $learnerRef) use ($lookupThrows): ?array {
				if ($lookupThrows === true) {
					throw new RuntimeException('database gone');
				}

				return ($this->profiles[$learnerRef] ?? null);
			}
		);
		// A portal write has no session: only the across-tenants lookup answers.
		$lookup->method('resolveAcrossTenants')->willReturnCallback(
			function (string $ncUserId) use ($lookupThrows): ?string {
				if ($lookupThrows === true) {
					throw new RuntimeException('database gone');
				}

				return ($this->refsByUser[$ncUserId] ?? null);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($hasUser === true ? $user : null);

		$this->store ??= new RegisterFaithfulStore();
		if ($cohortsThrow === true || $lookupThrows === true) {
			$this->store->failReads = 'database gone';
		}

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return new ExcuseRequestOwnerStamp(
			schemaResolver: $resolver,
			profiles: $lookup,
			logger: new NullLogger(),
			groupTeachers: new PupilGroupTeachers(objectService: $objectService),
			// The real collaborator, over the same lookup and the same session.
			writers: new PortalWriteSubject(
				profiles: $lookup,
				userSession: $session,
				logger: new NullLogger()
			),
		);
	}//end makeStamp()

	/**
	 * Seed the groups: pupil-1 is in Groep 7 (a teacher and a duo-partner who
	 * is listed only in teacherAssignments) and was in last year's Groep 6;
	 * pupil-2 is in another group.
	 *
	 * @return void
	 */
	private function seedGroups(): void {
		$this->store ??= new RegisterFaithfulStore();
		$this->store->rows['cohort'] = [
			[
				'id' => 'groep-7',
				'name' => 'Groep 7',
				'lifecycle' => 'active',
				'learnerIds' => ['pupil-1', 'pupil-3'],
				'teacherIds' => ['juf-7'],
				'teacherAssignments' => [
					['teacherId' => 'juf-7', 'role' => 'primary'],
					['teacherId' => 'duo-7', 'role' => 'duo-partner'],
				],
			],
			['id' => 'groep-6', 'name' => 'Groep 6', 'lifecycle' => 'archived', 'learnerIds' => ['pupil-1'], 'teacherIds' => ['juf-6']],
			['id' => 'groep-8', 'name' => 'Groep 8', 'lifecycle' => 'active', 'learnerIds' => ['pupil-2'], 'teacherIds' => ['juf-8']],
		];
	}//end seedGroups()

	/**
	 * Seed a pupil with one guardian who has an account and one who has none.
	 *
	 * @return void
	 */
	private function seedFamily(): void {
		$this->profiles['lp-1'] = [
			'id' => 'lp-1',
			'ncUserId' => 'pupil-1',
			'tenant_id' => self::TENANT,
			'lifecycle' => 'active',
			'guardianRefs' => ['gp-1', 'gp-2'],
		];
		$this->profiles['gp-1'] = ['id' => 'gp-1', 'ncUserId' => 'ouder-1', 'tenant_id' => self::TENANT, 'lifecycle' => 'active'];
		$this->refsByUser['pupil-1'] = 'lp-1';
		$this->refsByUser['pupil-2'] = 'lp-2';
	}//end seedFamily()

	/**
	 * The body portaliq writes for a portal absence report.
	 *
	 * @param array<string, mixed> $extra Fields beyond the whitelisted four.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function portalCreate(array $extra = []): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			OrEntityFactory::make(
				array_merge(
					[
						'dateFrom' => '2026-09-28',
						'dateTo' => '2026-09-28',
						'reason' => 'Koorts',
						'reasonKind' => 'illness',
					],
					$extra
				),
				'excuse-request'
			)
		);
	}//end portalCreate()

	/**
	 * The pupil's own session still takes the portal path, and a guardian's
	 * does too; anybody else's does not.
	 *
	 * WHY THIS TEST EXISTS. The portal branch used to be chosen by "nobody is
	 * signed in", which was right while every portal citizen was a DigiD
	 * guardian with no Nextcloud account. A PUPIL signs in to her portal with
	 * her school account, so her browser carries a Nextcloud session cookie
	 * and her own absence report was read as a staff write and refused with
	 * `excuse-owner-missing`. Measured on a live instance on 4 October 2026
	 * (pupil-flows.spec.ts, step d): the identical request with the identical
	 * bearer succeeded from a cookie-less context and failed from her browser.
	 *
	 * @return void
	 */
	public function testThePortalPathBelongsToWhoeverTheReportIsAttributedTo(): void {
		$this->seedFamily();
		$this->seedGroups();

		$hers = $this->portalCreate(extra: ['learnerRef' => 'lp-1']);
		$this->makeStamp(hasUser: true, uid: 'pupil-1')->handle($hers);
		self::assertFalse($hers->isPropagationStopped());
		self::assertSame('pupil-1', $hers->getModifiedData()['submittedBy']);

		$guardians = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'submittedByRef' => 'gp-1']);
		$this->makeStamp(hasUser: true, uid: 'ouder-1')->handle($guardians);
		self::assertFalse($guardians->isPropagationStopped());
		// `submittedByRef` came in with the body, so the stamp adds the
		// guardian's account and the level it was written at.
		self::assertSame('ouder-1', $guardians->getModifiedData()['submittedBy']);

		// A mentor's session is not the portal's: the report is not about them,
		// so the staff branch applies and the write needs a learnerId.
		$mentors = $this->portalCreate(extra: ['learnerRef' => 'lp-1']);
		$this->makeStamp(hasUser: true, uid: 'mentor-1')->handle($mentors);
		self::assertTrue($mentors->isPropagationStopped());
		self::assertSame('excuse-owner-missing', $mentors->getErrors()['reason']);
	}//end testThePortalPathBelongsToWhoeverTheReportIsAttributedTo()

	/**
	 * A pupil's own report gets the pupil, the pupil as submitter, the
	 * school and the portal's assurance level from the profile.
	 *
	 * @return void
	 */
	public function testAPupilReportIsStampedFromTheProfile(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1']);

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('pupil-1', $data['learnerId']);
		self::assertSame('lp-1', $data['learnerRef']);
		self::assertSame('pupil-1', $data['submittedBy']);
		self::assertSame('lp-1', $data['submittedByRef']);
		self::assertSame('basic', $data['submittedAuthLevel']);
		self::assertSame(self::TENANT, $data['tenant_id']);
	}//end testAPupilReportIsStampedFromTheProfile()

	/**
	 * A guardian's report names the child and the guardian, with the
	 * guardian's user id and the level the guardian action requires.
	 *
	 * @return void
	 */
	public function testAGuardianReportNamesTheChildAndTheGuardian(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'submittedByRef' => 'gp-1']);

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('pupil-1', $data['learnerId']);
		self::assertSame('ouder-1', $data['submittedBy']);
		self::assertArrayNotHasKey('submittedByRef', $data);
		self::assertSame('substantial', $data['submittedAuthLevel']);
		self::assertSame(self::TENANT, $data['tenant_id']);
	}//end testAGuardianReportNamesTheChildAndTheGuardian()

	/**
	 * A guardian without a Nextcloud account is named by submittedByRef alone,
	 * and the report is let through.
	 *
	 * @return void
	 */
	public function testAGuardianWithoutAnAccountIsNamedByReference(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'submittedByRef' => 'gp-2']);

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertNull($event->getModifiedData()['submittedBy']);
	}//end testAGuardianWithoutAnAccountIsNamedByReference()

	/**
	 * A guardian cannot report an absence for a child that does not list them.
	 *
	 * @return void
	 */
	public function testAGuardianOfAnotherChildIsRefused(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'submittedByRef' => 'gp-9']);

		$this->makeStamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('excuse-guardian-unknown', $event->getErrors()['reason']);
		self::assertSame([], $event->getModifiedData());
	}//end testAGuardianOfAnotherChildIsRefused()

	/**
	 * A learnerRef naming no active profile refuses the report, so no excuse
	 * without a pupil is written.
	 *
	 * @return void
	 */
	public function testAReportForAnUnknownPupilIsRefused(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-9']);

		$this->makeStamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('excuse-learner-unknown', $event->getErrors()['reason']);
	}//end testAReportForAnUnknownPupilIsRefused()

	/**
	 * A lookup that fails refuses the portal report: fail closed.
	 *
	 * @return void
	 */
	public function testAFailedLookupRefusesThePortalReport(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1']);

		$this->makeStamp(lookupThrows: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('excuse-lookup-failed', $event->getErrors()['reason']);
	}//end testAFailedLookupRefusesThePortalReport()

	/**
	 * A signed-in caller never takes the portal path: a create with only a
	 * learnerRef and no pupil is refused, so nobody reports in another pupil's
	 * name by sending a uuid.
	 *
	 * @return void
	 */
	public function testASignedInCreateWithoutAPupilIsRefused(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'tenant_id' => self::TENANT]);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('excuse-owner-missing', $event->getErrors()['reason']);
	}//end testASignedInCreateWithoutAPupilIsRefused()

	/**
	 * A staff create keeps its fields, gets learnerRef from learnerId (a
	 * forged value is replaced) and the default assurance level.
	 *
	 * @return void
	 */
	public function testAStaffCreateDerivesTheReferenceAndTheLevel(): void {
		$this->seedFamily();
		$event = $this->portalCreate(
			extra: [
				'learnerId' => 'pupil-1',
				'learnerRef' => 'lp-2',
				'submittedBy' => 'mentor-1',
				'tenant_id' => self::TENANT,
			]
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('lp-1', $data['learnerRef']);
		self::assertSame('basic', $data['submittedAuthLevel']);
		self::assertArrayNotHasKey('learnerId', $data);
	}//end testAStaffCreateDerivesTheReferenceAndTheLevel()

	/**
	 * A staff create keeps an assurance level it sends.
	 *
	 * @return void
	 */
	public function testAStaffCreateKeepsItsLevel(): void {
		$this->seedFamily();
		$event = $this->portalCreate(
			extra: [
				'learnerId' => 'pupil-1',
				'submittedBy' => 'mentor-1',
				'submittedAuthLevel' => 'high',
				'tenant_id' => self::TENANT,
			]
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertArrayNotHasKey('submittedAuthLevel', $event->getModifiedData());
	}//end testAStaffCreateKeepsItsLevel()

	/**
	 * Staff are held to the rule the schema's required list gave: no pupil, no
	 * submitter or no school refuses the write.
	 *
	 * @return void
	 */
	public function testAStaffCreateWithoutItsOwnerFieldsIsRefused(): void {
		$this->seedFamily();
		foreach (['learnerId', 'submittedBy', 'tenant_id'] as $missing) {
			$fields = ['learnerId' => 'pupil-1', 'submittedBy' => 'mentor-1', 'tenant_id' => self::TENANT];
			unset($fields[$missing]);
			$event = $this->portalCreate(extra: $fields);

			$this->makeStamp(hasUser: true)->handle($event);

			self::assertTrue($event->isPropagationStopped(), $missing);
			self::assertSame('excuse-owner-missing', $event->getErrors()['reason'], $missing);
		}
	}//end testAStaffCreateWithoutItsOwnerFieldsIsRefused()

	/**
	 * An update whose pupil did not change keeps its stored learnerRef when the
	 * lookup fails.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsTheStoredReferenceWhenTheLookupFails(): void {
		$stored = ['learnerId' => 'pupil-1', 'learnerRef' => 'lp-1', 'submittedBy' => 'mentor-1', 'submittedAuthLevel' => 'basic', 'tenant_id' => self::TENANT, 'lifecycle' => 'submitted'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['lifecycle' => 'approved']), 'excuse-request'),
			OrEntityFactory::make($stored, 'excuse-request')
		);

		$this->makeStamp(hasUser: true, lookupThrows: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
	}//end testAnUpdateKeepsTheStoredReferenceWhenTheLookupFails()

	/**
	 * A portal report lists the teachers of the pupil's current group,
	 * the duo-partner included, and not last year's teacher or another
	 * group's teacher.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#scenario-a-parents-report-reaches-the-group-teacher-and-nobody-elses
	 */
	public function testAPortalReportListsTheTeachersOfThePupilsGroup(): void {
		$this->seedFamily();
		$this->seedGroups();
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1', 'submittedByRef' => 'gp-1']);

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['juf-7', 'duo-7'], $event->getModifiedData()['teacherIds']);
		foreach ($this->store->reads as $read) {
			self::assertFalse($read['rbac'], 'a portal write has no session');
			self::assertFalse($read['multitenancy']);
		}
	}//end testAPortalReportListsTheTeachersOfThePupilsGroup()

	/**
	 * A client value is never kept: a teacher cannot add themselves to the
	 * audience of another group's report.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#scenario-nobody-adds-themselves-to-a-reports-audience
	 */
	public function testAClientCannotAddItselfToTheAudience(): void {
		$this->seedFamily();
		$this->seedGroups();
		$event = $this->portalCreate(
			extra: [
				'learnerId' => 'pupil-2',
				'submittedBy' => 'juf-7',
				'tenant_id' => self::TENANT,
				'teacherIds' => ['juf-7'],
			]
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertSame(['juf-8'], $event->getModifiedData()['teacherIds']);
	}//end testAClientCannotAddItselfToTheAudience()

	/**
	 * An update re-derives the teachers, so a report follows the pupil when
	 * the group's teachers change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
	 */
	public function testAnUpdateReDerivesTheTeachers(): void {
		$this->seedFamily();
		$this->seedGroups();
		$stored = ['learnerId' => 'pupil-1', 'submittedBy' => 'ouder-1', 'tenant_id' => self::TENANT, 'teacherIds' => ['juf-6'], 'lifecycle' => 'submitted'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['lifecycle' => 'approved']), 'excuse-request'),
			OrEntityFactory::make($stored, 'excuse-request')
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertSame(['juf-7', 'duo-7'], $event->getModifiedData()['teacherIds']);
	}//end testAnUpdateReDerivesTheTeachers()

	/**
	 * A create whose group lookup fails stamps nobody: the report then reaches
	 * school-wide staff only, never more people.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#scenario-a-failed-lookup-never-widens-the-audience
	 */
	public function testAFailedGroupLookupStampsNobodyOnCreate(): void {
		$this->seedFamily();
		$event = $this->portalCreate(extra: ['learnerId' => 'pupil-1', 'submittedBy' => 'mentor-1', 'tenant_id' => self::TENANT, 'teacherIds' => ['juf-8']]);

		$this->makeStamp(hasUser: true, cohortsThrow: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData()['teacherIds']);
	}//end testAFailedGroupLookupStampsNobodyOnCreate()

	/**
	 * An update whose pupil did not change keeps its stored teachers when the
	 * group lookup fails; one that moves the report to another pupil drops them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#scenario-a-failed-lookup-never-widens-the-audience
	 */
	public function testAnUpdateKeepsTheStoredTeachersWhenTheLookupFails(): void {
		$stored = ['learnerId' => 'pupil-1', 'learnerRef' => 'lp-1', 'submittedBy' => 'mentor-1', 'tenant_id' => self::TENANT, 'teacherIds' => ['juf-7'], 'lifecycle' => 'submitted'];
		$same = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['lifecycle' => 'approved']), 'excuse-request'),
			OrEntityFactory::make($stored, 'excuse-request')
		);
		$moved = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['learnerId' => 'pupil-2']), 'excuse-request'),
			OrEntityFactory::make($stored, 'excuse-request')
		);

		$this->makeStamp(hasUser: true, cohortsThrow: true)->handle($same);
		$this->makeStamp(hasUser: true, cohortsThrow: true)->handle($moved);

		self::assertSame(['juf-7'], $same->getModifiedData()['teacherIds']);
		self::assertSame([], $moved->getModifiedData()['teacherIds']);
	}//end testAnUpdateKeepsTheStoredTeachersWhenTheLookupFails()

	/**
	 * What the stamp writes for a portal report passes the shipped
	 * ExcuseRequest schema the way OpenRegister validates a write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/excuse-reports-follow-the-pupils-group/specs/attendance/spec.md#requirement-an-absence-report-is-read-by-the-teachers-of-the-pupils-group-and-by-school-wide-staff
	 */
	public function testTheStampedReportPassesTheRealSchema(): void {
		$pupilRef = '22222222-2222-4222-8222-222222222222';
		$this->profiles[$pupilRef] = ['id' => $pupilRef, 'ncUserId' => 'pupil-1', 'tenant_id' => self::TENANT, 'lifecycle' => 'active'];
		$this->seedGroups();
		$event = $this->portalCreate(extra: ['learnerRef' => $pupilRef]);

		$this->makeStamp()->handle($event);
		self::assertFalse($event->isPropagationStopped());

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$schema = (string)json_encode($this->validatable(schema: $register['components']['schemas']['ExcuseRequest']));
		$written = array_merge($event->getObject()->getObject(), $event->getModifiedData());

		$validator = new Validator();
		$result = $validator->validate(json_decode((string)json_encode($written)), $schema);
		self::assertTrue($result->isValid(), (string)json_encode($result->error()?->message()));
		self::assertSame(['juf-7', 'duo-7'], $written['teacherIds']);

		$control = array_merge($written, ['teacherIds' => [7]]);
		self::assertFalse($validator->validate(json_decode((string)json_encode($control)), $schema)->isValid(), 'control: teacher ids are strings');
	}//end testTheStampedReportPassesTheRealSchema()

	/**
	 * The schema without OpenRegister's own keys, which a JSON Schema validator cannot resolve.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		return $clean;
	}//end validatable()

	/**
	 * Another schema's writes are never touched.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = $this->portalCreate(extra: ['learnerRef' => 'lp-1']);

		$this->makeStamp(schemaSlug: 'submission')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * The stamp is wired on create and update, so the rule holds for both.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredOnCreateAndUpdate(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . ExcuseRequestOwnerStamp::class, $pairs);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . ExcuseRequestOwnerStamp::class, $pairs);
	}//end testTheStampIsRegisteredOnCreateAndUpdate()
}//end class
