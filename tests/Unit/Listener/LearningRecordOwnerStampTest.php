<?php

/**
 * Learniq LearningRecordOwnerStamp unit tests.
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
 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-generated-export-names-every-source-object-s-outcome
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\LearningRecordOwnerStamp;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\LearningRecordAggregationService;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LearningRecordOwnerStamp::handle().
 */
class LearningRecordOwnerStampTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000001';

	private const OWN_REF = 'aaaaaaaa-0000-4000-8000-000000000001';

	private const NOW = 1792540800;

	private const NOW_ISO = '2026-10-21T00:00:00+00:00';

	/**
	 * Build the stamp, signed in as $userId. Only 'learner' has a learner
	 * profile and a tenant binding; 'admin' is an administrator and 'mentor'
	 * an instructor.
	 *
	 * @param string|null $userId The session user, null for no session.
	 * @param string      $slug   What the schema resolver answers.
	 *
	 * @return LearningRecordOwnerStamp
	 */
	private function makeStamp(?string $userId, string $slug): LearningRecordOwnerStamp {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $user, string $app, string $key, mixed $default = ''): mixed => ($user === 'learner' && $key === 'tenant_id' ? self::TENANT : $default)
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		$records = $this->createMock(LearningRecordAggregationService::class);
		$records->method('resolveLearnerRefForUser')->willReturnCallback(
			static fn (string $ncUserId): ?string => ($ncUserId === 'learner' ? self::OWN_REF : null)
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'mentor' && $group === 'instructors');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('@' . self::NOW));

		return new LearningRecordOwnerStamp(
			schemaResolver: $schemaResolver,
			tenants: new CallerTenantResolver($config, $this->createMock(ObjectService::class)),
			learningRecords: $records,
			groupManager: $groups,
			userSession: $session,
			timeFactory: $time,
		);
	}//end makeStamp()

	/**
	 * An export is of the requester's own record, whatever learner the
	 * client named.
	 *
	 * @return void
	 */
	public function testAnExportIsOfTheRequestersOwnRecord(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(
				[
					'learnerId' => 'classmate',
					'learnerRef' => 'bbbbbbbb-0000-4000-8000-000000000002',
					'requestedBy' => 'classmate',
					'requestedAt' => '2001-01-01T00:00:00Z',
					'tenant_id' => '99999999-0000-4000-8000-000000000009',
					'periodFrom' => '2026-01-01',
				],
				'learning-record-export'
			)
		);
		$this->makeStamp(userId: 'learner', slug: 'learning-record-export')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('learner', $data['learnerId']);
		self::assertSame(self::OWN_REF, $data['learnerRef']);
		self::assertSame('learner', $data['requestedBy']);
		self::assertSame(self::NOW_ISO, $data['requestedAt']);
		self::assertSame(self::TENANT, $data['tenant_id']);
		self::assertArrayNotHasKey('periodFrom', $data);
	}//end testAnExportIsOfTheRequestersOwnRecord()

	/**
	 * A share is made by and for the signed-in learner.
	 *
	 * @return void
	 */
	public function testAShareIsMadeByTheSignedInLearner(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(['learnerId' => 'classmate', 'sharedBy' => 'classmate', 'recipientName' => 'Employer'], 'learning-record-share')
		);
		$this->makeStamp(userId: 'learner', slug: 'learning-record-share')->handle($event);

		$data = $event->getModifiedData();
		self::assertSame('learner', $data['learnerId']);
		self::assertSame(self::OWN_REF, $data['learnerRef']);
		self::assertSame('learner', $data['sharedBy']);
		self::assertSame(self::TENANT, $data['tenant_id']);
		self::assertArrayNotHasKey('requestedBy', $data);
	}//end testAShareIsMadeByTheSignedInLearner()

	/**
	 * An instructor exports a learner's record on their behalf: the learner's
	 * own profile and tenant, with the instructor as the requester.
	 *
	 * @return void
	 */
	public function testAnInstructorExportsOnALearnersBehalf(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(['learnerId' => 'learner', 'learnerRef' => 'bbbbbbbb-0000-4000-8000-000000000002'], 'learning-record-export')
		);
		$this->makeStamp(userId: 'mentor', slug: 'learning-record-export')->handle($event);

		$data = $event->getModifiedData();
		self::assertSame('learner', $data['learnerId']);
		self::assertSame(self::OWN_REF, $data['learnerRef']);
		self::assertSame(self::TENANT, $data['tenant_id']);
		self::assertSame('mentor', $data['requestedBy']);
	}//end testAnInstructorExportsOnALearnersBehalf()

	/**
	 * A user without a learner profile has no record to export or share.
	 *
	 * @return void
	 */
	public function testAUserWithoutALearnerProfileIsRefused(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'learner'], 'learning-record-export'));
		$this->makeStamp(userId: 'teacher', slug: 'learning-record-export')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('learning-record-no-profile', $event->getErrors()['reason']);
	}//end testAUserWithoutALearnerProfileIsRefused()

	/**
	 * An update cannot move an export to another learner or requester.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsTheStoredOwner(): void {
		$stored = [
			'learnerId' => 'learner',
			'learnerRef' => self::OWN_REF,
			'requestedBy' => 'learner',
			'requestedAt' => '2026-10-01T00:00:00+00:00',
			'tenant_id' => self::TENANT,
			'lifecycle' => 'requested',
		];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['learnerRef' => 'bbbbbbbb-0000-4000-8000-000000000002', 'learnerId' => 'classmate']), 'learning-record-export'),
			OrEntityFactory::make($stored, 'learning-record-export')
		);
		$this->makeStamp(userId: 'learner', slug: 'learning-record-export')->handle($event);

		$data = $event->getModifiedData();
		self::assertSame('learner', $data['learnerId']);
		self::assertSame(self::OWN_REF, $data['learnerRef']);
		self::assertSame('learner', $data['requestedBy']);
		self::assertSame('2026-10-01T00:00:00+00:00', $data['requestedAt']);
		self::assertSame(self::TENANT, $data['tenant_id']);
	}//end testAnUpdateKeepsTheStoredOwner()

	/**
	 * An administrator and a write without a session (seed import) keep what
	 * they send.
	 *
	 * @return void
	 */
	public function testAnAdministratorAndASystemWriteAreLeftAlone(): void {
		foreach (['admin', null] as $userId) {
			$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'he-student-081'], 'learning-record-export'));
			$this->makeStamp(userId: $userId, slug: 'learning-record-export')->handle($event);

			self::assertFalse($event->isPropagationStopped());
			self::assertSame([], $event->getModifiedData());
		}
	}//end testAnAdministratorAndASystemWriteAreLeftAlone()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'classmate'], 'enrolment'));
		$this->makeStamp(userId: 'learner', slug: 'enrolment')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The schemas leave the stamped fields out of `required`: OpenRegister
	 * checks it before the stamp runs.
	 *
	 * @return void
	 */
	public function testTheStampedFieldsAreNotRequired(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$schemas = $register['components']['schemas'];

		self::assertSame([], array_values(array_intersect($schemas['LearningRecordExport']['required'], ['learnerId', 'learnerRef', 'requestedBy', 'requestedAt', 'tenant_id'])));
		self::assertSame([], array_values(array_intersect($schemas['LearningRecordShare']['required'], ['learnerId', 'learnerRef', 'sharedBy', 'tenant_id'])));
	}//end testTheStampedFieldsAreNotRequired()

	/**
	 * The stamp is wired on both create and update.
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

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . LearningRecordOwnerStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . LearningRecordOwnerStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
