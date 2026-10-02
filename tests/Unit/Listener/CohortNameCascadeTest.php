<?php

/**
 * Tests for CohortNameCascade.
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

use OCA\Learniq\Listener\CohortNameCascade;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for CohortNameCascade::handle().
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
	 * Build the cascade over the fake store, with two enrolments in the group
	 * and one in another group.
	 *
	 * @param string $slug What the schema resolver answers for the entity.
	 *
	 * @return CohortNameCascade
	 */
	private function makeCascade(string $slug = 'cohort'): CohortNameCascade {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'enrolment' => [
				['id' => 'enrolment-vera', 'cohortId' => self::COHORT, 'cohortName' => 'Groep 6', '@self' => ['owner' => 'someone']],
				['id' => 'enrolment-sem', 'cohortId' => self::COHORT, 'cohortName' => 'Groep 6'],
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

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		return new CohortNameCascade(schemaResolver: $schemaResolver, objectService: $objectService, logger: new NullLogger());
	}//end makeCascade()

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
	 * A renamed group writes its new name on its own enrolments only, without `@self`.
	 *
	 * @return void
	 */
	public function testARenameRestampsTheGroupsEnrolments(): void {
		$this->makeCascade()->handle($this->rename(newName: 'Groep 7', oldName: 'Groep 6'));

		self::assertCount(2, $this->store->saves);
		foreach ($this->store->saves as $save) {
			self::assertSame('enrolment', $save['schema']);
			self::assertSame('Groep 7', $save['object']['cohortName']);
			self::assertArrayNotHasKey('@self', $save['object']);
		}

		self::assertSame(['enrolment-vera', 'enrolment-sem'], array_column($this->store->saves, 'uuid'));
	}//end testARenameRestampsTheGroupsEnrolments()

	/**
	 * An update that keeps the name writes nothing.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutRenameWritesNothing(): void {
		$this->makeCascade()->handle($this->rename(newName: 'Groep 6', oldName: 'Groep 6'));

		self::assertSame([], $this->store->saves);
	}//end testAnUpdateWithoutRenameWritesNothing()

	/**
	 * Another schema's update is ignored.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$this->makeCascade(slug: 'course')->handle($this->rename(newName: 'Groep 7', oldName: 'Groep 6'));

		self::assertSame([], $this->store->saves);
	}//end testAnotherSchemaIsIgnored()

	/**
	 * A failed read never breaks the rename.
	 *
	 * @return void
	 */
	public function testAFailedReadIsSwallowed(): void {
		$cascade = $this->makeCascade();
		$this->store->failReads = 'database gone';
		$cascade->handle($this->rename(newName: 'Groep 7', oldName: 'Groep 6'));

		self::assertSame([], $this->store->saves);
	}//end testAFailedReadIsSwallowed()
}//end class
