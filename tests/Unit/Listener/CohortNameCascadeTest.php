<?php

/**
 * Tests for CohortNameCascade, CohortNameRestampJob and CohortNameRestamp.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\BackgroundJob\CohortNameRestampJob;
use OCA\Learniq\Listener\CohortNameCascade;
use OCA\Learniq\Service\CohortNameRestamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * A rename is queued by the listener and written by the job, after the request.
 */
class CohortNameCascadeTest extends TestCase {

	private const COHORT = 'ee010003-0000-4000-8000-000000000007';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * What the listener queued.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $deferred = [];

	/**
	 * The listener over a recording deferral double.
	 *
	 * @param string $slug What the schema resolver answers for the entity.
	 *
	 * @return CohortNameCascade
	 */
	private function makeCascade(string $slug = 'cohort'): CohortNameCascade {
		$this->deferred = [];
		$deferral = $this->createMock(ListenerDeferralService::class);
		$deferral->method('defer')->willReturnCallback(
			function (string $jobClass, array $entry, int $chunkSize = 50, ?string $dedupeKey = null): void {
				$this->deferred[] = ['jobClass' => $jobClass, 'entry' => $entry, 'dedupeKey' => $dedupeKey];
			}
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new CohortNameCascade(schemaResolver: $schemaResolver, deferral: $deferral);
	}//end makeCascade()

	/**
	 * The re-stamp service over the fake store, with two enrolments in the
	 * group and one in another group.
	 *
	 * @return CohortNameRestamp
	 */
	private function makeRestamp(): CohortNameRestamp {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'enrolment' => [
				['id' => 'enrolment-vera', 'cohortId' => self::COHORT, 'cohortName' => 'Groep 6', '@self' => ['owner' => 'someone']],
				['id' => 'enrolment-sem', 'cohortId' => self::COHORT, 'cohortName' => 'Groep 6'],
				['id' => 'enrolment-ok', 'cohortId' => self::COHORT, 'cohortName' => 'Groep 7'],
				['id' => 'enrolment-other', 'cohortId' => 'another-group', 'cohortName' => 'Groep 3'],
			],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		return new CohortNameRestamp(objectService: $objectService, logger: new NullLogger());
	}//end makeRestamp()

	/**
	 * A cohort update event.
	 *
	 * @param string $newName The stored name.
	 * @param string $oldName The name before.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function rename(string $newName, string $oldName): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(
			OrEntityFactory::make(['id' => self::COHORT, 'name' => $newName], 'cohort', self::COHORT),
			OrEntityFactory::make(['id' => self::COHORT, 'name' => $oldName], 'cohort', self::COHORT)
		);
	}//end rename()

	/**
	 * A rename queues one job entry and writes nothing in the request.
	 *
	 * @return void
	 */
	public function testARenameIsQueuedNotWritten(): void {
		$this->makeCascade()->handle($this->rename(newName: 'Groep 7', oldName: 'Groep 6'));

		self::assertSame(
			[['jobClass' => CohortNameRestampJob::class, 'entry' => ['cohortId' => self::COHORT, 'name' => 'Groep 7'], 'dedupeKey' => 'cohort-name:' . self::COHORT]],
			$this->deferred
		);
	}//end testARenameIsQueuedNotWritten()

	/**
	 * An update that keeps the name queues nothing; another schema neither.
	 *
	 * @return void
	 */
	public function testNoRenameNoJob(): void {
		$this->makeCascade()->handle($this->rename(newName: 'Groep 6', oldName: 'Groep 6'));
		self::assertSame([], $this->deferred);

		$this->makeCascade(slug: 'course')->handle($this->rename(newName: 'Groep 7', oldName: 'Groep 6'));
		self::assertSame([], $this->deferred);
	}//end testNoRenameNoJob()

	/**
	 * The job writes the last buffered name of each group on its enrolments
	 * that carry another name, without `@self`.
	 *
	 * @return void
	 */
	public function testTheJobRestampsTheGroupsEnrolments(): void {
		$job = new CohortNameRestampJob(
			time: $this->createMock(ITimeFactory::class),
			userSession: $this->createMock(IUserSession::class),
			userManager: $this->createMock(IUserManager::class),
			organisation: $this->createMock(OrganisationService::class),
			logger: new NullLogger(),
			restamp: $this->makeRestamp()
		);
		$entries = [
			['cohortId' => self::COHORT, 'name' => 'Groep 6b'],
			['cohortId' => self::COHORT, 'name' => 'Groep 7'],
			['cohortId' => '', 'name' => 'Ignored'],
		];
		(new ReflectionMethod($job, 'runDeferred'))->invoke($job, new DeferredListenerContext(userId: 'admin', orgUuid: null, entries: $entries));

		self::assertSame(['enrolment-vera', 'enrolment-sem'], array_column($this->store->saves, 'uuid'));
		foreach ($this->store->saves as $save) {
			self::assertSame('enrolment', $save['schema']);
			self::assertSame('Groep 7', $save['object']['cohortName']);
			self::assertArrayNotHasKey('@self', $save['object']);
		}
	}//end testTheJobRestampsTheGroupsEnrolments()

	/**
	 * A failed read writes nothing and does not throw.
	 *
	 * @return void
	 */
	public function testAFailedReadIsSwallowed(): void {
		$restamp = $this->makeRestamp();
		$this->store->failReads = 'database gone';
		$restamp->restamp(cohortId: self::COHORT, name: 'Groep 7');

		self::assertSame([], $this->store->saves);
	}//end testAFailedReadIsSwallowed()
}//end class
