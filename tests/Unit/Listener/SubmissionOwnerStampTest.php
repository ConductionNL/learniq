<?php

/**
 * Learniq SubmissionOwnerStamp unit tests.
 *
 * The portal cases build the creating event from exactly the body portaliq's
 * `PortalObjectWriter::createObject()` hands OpenRegister for `createSubmission`:
 * the whitelisted `assignmentId`, the `learnerRef` scope stamp and the
 * `organisation`, with no Nextcloud session. That is the stubbed portal request.
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
 * @spec openspec/specs/assignments/spec.md#requirement-the-server-stamps-who-a-submission-belongs-to
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\SubmissionOwnerStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for SubmissionOwnerStamp::handle().
 */
class SubmissionOwnerStampTest extends TestCase {

	private const TENANT = '11111111-1111-4111-8111-111111111111';
	private const OTHER_TENANT = '22222222-2222-4222-8222-222222222222';

	/**
	 * Active LearnerProfile rows keyed by uuid, as LearnerRefResolver::byRef returns them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $profiles = [];

	/**
	 * Profile uuid per Nextcloud user id, as LearnerRefResolver::resolveAcrossTenants() returns it.
	 *
	 * @var array<string, string>
	 */
	private array $refsByUser = [];

	/**
	 * Assignment rows keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $assignments = [];

	/**
	 * Build the stamp.
	 *
	 * @param string $schemaSlug Slug the resolver returns for the entity.
	 * @param bool $hasUser Whether a Nextcloud session exists.
	 * @param bool $lookupThrows Whether every profile lookup throws.
	 *
	 * @return SubmissionOwnerStamp
	 */
	private function makeStamp(string $schemaSlug = 'submission', bool $hasUser = false, bool $lookupThrows = false): SubmissionOwnerStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		$lookup = $this->createMock(LearnerRefResolver::class);
		$lookup->method('byRef')->willReturnCallback(
			function (string $learnerRef) use ($lookupThrows): ?array {
				if ($lookupThrows === true) {
					throw new RuntimeException('database gone');
				}

				return $this->profiles[$learnerRef] ?? null;
			}
		);
		// The owner stamp may run without a session: only the across-tenants lookup answers.
		$lookup->method('resolveAcrossTenants')->willReturnCallback(
			function (string $ncUserId) use ($lookupThrows): ?string {
				if ($lookupThrows === true) {
					throw new RuntimeException('database gone');
				}

				return $this->refsByUser[$ncUserId] ?? null;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				if ($schema !== 'assignment' || isset($this->assignments[$id]) === false) {
					throw new DoesNotExistException('not found');
				}

				return OrEntityFactory::make(array_merge($this->assignments[$id], ['id' => $id]), 'assignment');
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($hasUser === true ? $user : null);

		return new SubmissionOwnerStamp(
			schemaResolver: $resolver,
			profiles: $lookup,
			objectService: $objectService,
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * The body portaliq writes for a portal hand-in.
	 *
	 * @param string $learnerRef The scope stamp.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function portalCreate(string $learnerRef = 'lp-1'): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			OrEntityFactory::make(
				[
					'assignmentId' => 'as-1',
					'learnerRef' => $learnerRef,
					'organisation' => 'org-1',
				],
				'submission'
			)
		);
	}//end portalCreate()

	/**
	 * Seed the pupil and the assignment of the happy path.
	 *
	 * @return void
	 */
	private function seedPupil(): void {
		$this->profiles['lp-1'] = ['id' => 'lp-1', 'ncUserId' => 'pupil-1', 'tenant_id' => self::TENANT, 'lifecycle' => 'active'];
		$this->refsByUser['pupil-1'] = 'lp-1';
		$this->assignments['as-1'] = ['title' => 'Essay', 'tenant_id' => self::TENANT];
	}//end seedPupil()

	/**
	 * A portal hand-in gets its learners and tenant from the pupil's profile and
	 * the assignment, and is let through.
	 *
	 * @return void
	 */
	public function testAPortalHandInIsStampedFromTheProfile(): void {
		$this->seedPupil();
		$event = $this->portalCreate();

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame(['pupil-1'], $data['learnerIds']);
		self::assertSame(['lp-1'], $data['learnerRefs']);
		self::assertSame('lp-1', $data['learnerRef']);
		self::assertSame(self::TENANT, $data['tenant_id']);
	}//end testAPortalHandInIsStampedFromTheProfile()

	/**
	 * The tenant falls back to the profile's when the assignment has none.
	 *
	 * @return void
	 */
	public function testTheProfileTenantIsUsedWhenTheAssignmentHasNone(): void {
		$this->seedPupil();
		$this->assignments['as-1']['tenant_id'] = '';
		$event = $this->portalCreate();

		$this->makeStamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(self::TENANT, $event->getModifiedData()['tenant_id']);
	}//end testTheProfileTenantIsUsedWhenTheAssignmentHasNone()

	/**
	 * A learnerRef naming no active profile (unknown, merged, deleted) refuses
	 * the hand-in, so no row without a learner is written.
	 *
	 * @return void
	 */
	public function testAPortalHandInForAnUnknownPupilIsRefused(): void {
		$this->seedPupil();
		$event = $this->portalCreate(learnerRef: 'lp-9');

		$this->makeStamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('submission-learner-unknown', $event->getErrors()['reason']);
		self::assertSame([], $event->getModifiedData());
	}//end testAPortalHandInForAnUnknownPupilIsRefused()

	/**
	 * A hand-in to an assignment that does not exist is refused.
	 *
	 * @return void
	 */
	public function testAPortalHandInForAMissingAssignmentIsRefused(): void {
		$this->seedPupil();
		unset($this->assignments['as-1']);
		$event = $this->portalCreate();

		$this->makeStamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('submission-assignment-unknown', $event->getErrors()['reason']);
	}//end testAPortalHandInForAMissingAssignmentIsRefused()

	/**
	 * A pupil of one school cannot hand in to another school's assignment.
	 *
	 * @return void
	 */
	public function testAPortalHandInAcrossTenantsIsRefused(): void {
		$this->seedPupil();
		$this->assignments['as-1']['tenant_id'] = self::OTHER_TENANT;
		$event = $this->portalCreate();

		$this->makeStamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('submission-tenant-mismatch', $event->getErrors()['reason']);
	}//end testAPortalHandInAcrossTenantsIsRefused()

	/**
	 * A lookup that fails refuses the portal hand-in: fail closed.
	 *
	 * @return void
	 */
	public function testAFailedLookupRefusesThePortalHandIn(): void {
		$this->seedPupil();
		$event = $this->portalCreate();

		$this->makeStamp(lookupThrows: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('submission-lookup-failed', $event->getErrors()['reason']);
	}//end testAFailedLookupRefusesThePortalHandIn()

	/**
	 * A signed-in caller never takes the portal path: sending only a learnerRef
	 * does not hand in in somebody else's name.
	 *
	 * @return void
	 */
	public function testASignedInCallerCannotUseThePortalPath(): void {
		$this->seedPupil();
		$event = $this->portalCreate();

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('submission-owner-missing', $event->getErrors()['reason']);
	}//end testASignedInCallerCannotUseThePortalPath()

	/**
	 * A staff create without learners is refused, as `required` used to do.
	 *
	 * @return void
	 */
	public function testAStaffCreateWithoutLearnersIsRefused(): void {
		$this->seedPupil();
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(['assignmentId' => 'as-1', 'tenant_id' => self::TENANT], 'submission')
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('submission-owner-missing', $event->getErrors()['reason']);
		self::assertStringContainsString('school', $event->getErrors()['message']);
	}//end testAStaffCreateWithoutLearnersIsRefused()

	/**
	 * A staff create without a tenant is refused too.
	 *
	 * @return void
	 */
	public function testAStaffCreateWithoutTenantIsRefused(): void {
		$this->seedPupil();
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(['assignmentId' => 'as-1', 'learnerIds' => ['pupil-1']], 'submission')
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('submission-owner-missing', $event->getErrors()['reason']);
	}//end testAStaffCreateWithoutTenantIsRefused()

	/**
	 * An app create gets learnerRef from learnerIds[0]; a forged one is replaced.
	 *
	 * @return void
	 */
	public function testAForgedLearnerRefIsReplaced(): void {
		$this->seedPupil();
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(
				['assignmentId' => 'as-1', 'learnerIds' => ['pupil-1'], 'tenant_id' => self::TENANT, 'learnerRef' => 'lp-2'],
				'submission'
			)
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['learnerRef' => 'lp-1'], $event->getModifiedData());
	}//end testAForgedLearnerRefIsReplaced()

	/**
	 * A learner without a profile keeps the write and gets a null learnerRef:
	 * the submission stays out of the portal.
	 *
	 * @return void
	 */
	public function testALearnerWithoutAProfileGetsANullRef(): void {
		$this->seedPupil();
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(
				['assignmentId' => 'as-1', 'learnerIds' => ['pupil-7'], 'tenant_id' => self::TENANT, 'learnerRef' => 'lp-1'],
				'submission'
			)
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['learnerRef' => null], $event->getModifiedData());
	}//end testALearnerWithoutAProfileGetsANullRef()

	/**
	 * Portaliq's file attach is an update without a session; when the lookup
	 * fails and the learners did not change, the stored learnerRef stays.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnUpdateKeepsTheStoredRef(): void {
		$stored = ['assignmentId' => 'as-1', 'learnerIds' => ['pupil-1'], 'tenant_id' => self::TENANT, 'learnerRef' => 'lp-1'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['attachmentRefs' => ['101'], 'learnerRef' => 'lp-2']), 'submission'),
			OrEntityFactory::make($stored, 'submission')
		);

		$this->makeStamp(lookupThrows: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['learnerRef' => 'lp-1'], $event->getModifiedData());
	}//end testAFailedLookupOnUpdateKeepsTheStoredRef()

	/**
	 * When an update moves the submission to other learners, a failed lookup
	 * cannot keep the old ref: it names the wrong pupil.
	 *
	 * @return void
	 */
	public function testAFailedLookupOnAMovedSubmissionFailsClosed(): void {
		$stored = ['assignmentId' => 'as-1', 'learnerIds' => ['pupil-1'], 'tenant_id' => self::TENANT, 'learnerRef' => 'lp-1'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['learnerIds' => ['pupil-2']]), 'submission'),
			OrEntityFactory::make($stored, 'submission')
		);

		$this->makeStamp(hasUser: true, lookupThrows: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['learnerRef' => null], $event->getModifiedData());
	}//end testAFailedLookupOnAMovedSubmissionFailsClosed()

	/**
	 * An update that blanks the tenant is refused.
	 *
	 * @return void
	 */
	public function testAnUpdateThatBlanksTheTenantIsRefused(): void {
		$this->seedPupil();
		$stored = ['assignmentId' => 'as-1', 'learnerIds' => ['pupil-1'], 'tenant_id' => self::TENANT, 'learnerRef' => 'lp-1'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['tenant_id' => '']), 'submission'),
			OrEntityFactory::make($stored, 'submission')
		);

		$this->makeStamp(hasUser: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('submission-owner-missing', $event->getErrors()['reason']);
	}//end testAnUpdateThatBlanksTheTenantIsRefused()

	/**
	 * Another schema's writes are never touched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreIgnored(): void {
		$event = $this->portalCreate();

		$this->makeStamp(schemaSlug: 'excuse-request')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getModifiedData());
	}//end testOtherSchemasAreIgnored()

	/**
	 * The stamp is wired for create and update, asserted from the registrar.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . SubmissionOwnerStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . SubmissionOwnerStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
