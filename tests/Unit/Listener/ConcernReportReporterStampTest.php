<?php

/**
 * Learniq ConcernReportReporterStamp unit tests.
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
 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-the-server-decides-who-filed-a-report
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\ConcernReportReporterStamp;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * Tests for ConcernReportReporterStamp::handle().
 */
class ConcernReportReporterStampTest extends TestCase {

	private const PROFILE = '1a2b3c4d-0000-4000-8000-000000000012';
	private const TENANT = '00000000-0000-4000-8000-000000000001';

	/**
	 * When true, every profile lookup throws.
	 *
	 * @var bool
	 */
	private bool $failReads = false;

	/**
	 * Build the stamp over a real LearnerRefResolver, signed in as $userId.
	 *
	 * @param string|null $userId The session user, null for no session.
	 * @param string      $slug   What the schema resolver answers.
	 *
	 * @return ConcernReportReporterStamp
	 */
	private function makeStamp(?string $userId, string $slug = 'concern-report'): ConcernReportReporterStamp {
		$profile = ['id' => self::PROFILE, 'ncUserId' => 'lrn-12', 'lifecycle' => 'active', 'tenant_id' => self::TENANT];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($profile): array {
				if ($this->failReads === true) {
					throw new RuntimeException('database gone');
				}

				if (($config['filters']['ncUserId'] ?? '') === 'lrn-12') {
					return [OrEntityFactory::make($profile, 'learner-profile')];
				}

				return [];
			}
		);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id) use ($profile): ObjectEntity {
				if ((string)$id === self::PROFILE) {
					return OrEntityFactory::make($profile, 'learner-profile');
				}

				throw new DoesNotExistException('gone');
			}
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

		return new ConcernReportReporterStamp(
			schemaResolver: $schemaResolver,
			profiles: new LearnerRefResolver(objectService: $objectService),
			userSession: $session,
			logger: new NullLogger(),
		);
	}//end makeStamp()

	/**
	 * A report a learner files in a classmate's name is stored in their own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-a-report-cannot-be-filed-in-someone-elses-name
	 */
	public function testAReportCannotBeFiledInSomeoneElsesName(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['topic' => 'bullying', 'description' => 'Pushed in the corridor.', 'reporterId' => 'lrn-13'], 'concern-report'));
		$this->makeStamp(userId: 'lrn-12')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('lrn-12', $event->getModifiedData()['reporterId']);
		self::assertSame(self::TENANT, $event->getModifiedData()['tenant_id']);
	}//end testAReportCannotBeFiledInSomeoneElsesName()

	/**
	 * A new report is always received, whatever status the client sends.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-a-learner-files-a-report-and-sees-it
	 */
	public function testANewReportIsAlwaysReceived(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['topic' => 'bullying', 'description' => 'x', 'status' => 'closed'], 'concern-report'));
		$this->makeStamp(userId: 'lrn-12')->handle($event);

		self::assertSame('received', $event->getModifiedData()['status']);
	}//end testANewReportIsAlwaysReceived()

	/**
	 * A member of staff without a learner profile files with no tenant.
	 *
	 * @return void
	 */
	public function testStaffWithoutAProfileFileWithoutATenant(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['topic' => 'harassment', 'description' => 'x', 'tenant_id' => self::TENANT], 'concern-report'));
		$this->makeStamp(userId: 'teacher-1')->handle($event);

		self::assertSame('teacher-1', $event->getModifiedData()['reporterId']);
		self::assertNull($event->getModifiedData()['tenant_id']);
	}//end testStaffWithoutAProfileFileWithoutATenant()

	/**
	 * A failed profile lookup still files the report, under the session user.
	 *
	 * @return void
	 */
	public function testAFailedLookupStillFilesTheReport(): void {
		$this->failReads = true;
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['topic' => 'other', 'description' => 'x'], 'concern-report'));
		$this->makeStamp(userId: 'lrn-12')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('lrn-12', $event->getModifiedData()['reporterId']);
		self::assertNull($event->getModifiedData()['tenant_id']);
	}//end testAFailedLookupStillFilesTheReport()

	/**
	 * A create without a signed-in user is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#requirement-the-server-decides-who-filed-a-report
	 */
	public function testACreateWithoutASessionIsRefused(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['topic' => 'bullying', 'description' => 'x', 'reporterId' => 'lrn-13'], 'concern-report'));
		$this->makeStamp(userId: null)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('concern-no-session', $event->getErrors()['reason']);
		self::assertArrayNotHasKey('reporterId', $event->getModifiedData());
	}//end testACreateWithoutASessionIsRefused()

	/**
	 * A counsellor's update cannot move a report to another person.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/support-confidential-concern-report/specs/confidential-counsel/spec.md#scenario-a-counsellor-cannot-move-a-report-to-another-person
	 */
	public function testACounsellorCannotMoveAReportToAnotherPerson(): void {
		$stored = ['topic' => 'bullying', 'description' => 'x', 'status' => 'received', 'reporterId' => 'lrn-12', 'tenant_id' => self::TENANT];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['status' => 'in-progress', 'reporterId' => 'lrn-13', 'tenant_id' => null]), 'concern-report'),
			OrEntityFactory::make($stored, 'concern-report')
		);
		$this->makeStamp(userId: 'vp-01')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('lrn-12', $event->getModifiedData()['reporterId']);
		self::assertSame(self::TENANT, $event->getModifiedData()['tenant_id']);
	}//end testACounsellorCannotMoveAReportToAnotherPerson()

	/**
	 * An update of a stored report without a reporter is refused.
	 *
	 * @return void
	 */
	public function testAnUpdateOfAReportWithoutAReporterIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(['topic' => 'other', 'description' => 'x', 'reporterId' => 'lrn-13'], 'concern-report'),
			OrEntityFactory::make(['topic' => 'other', 'description' => 'x'], 'concern-report')
		);
		$this->makeStamp(userId: 'vp-01')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('concern-reporter-missing', $event->getErrors()['reason']);
	}//end testAnUpdateOfAReportWithoutAReporterIsRefused()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['reporterId' => 'lrn-13'], 'confidential-note'));
		$this->makeStamp(userId: 'lrn-12', slug: 'confidential-note')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The stamp is wired on both create and update, asserted from the caller.
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

		self::assertContains(ObjectCreatingEvent::class . ' => ' . ConcernReportReporterStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . ConcernReportReporterStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
