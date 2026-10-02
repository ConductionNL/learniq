<?php

/**
 * Tests for BackfillReadableCopies.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
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

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillReadableCopies;
use OCA\Learniq\Service\ReadableCopies;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for BackfillReadableCopies::run().
 */
class BackfillReadableCopiesTest extends TestCase {

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Build the step over a real ReadableCopies and the fake store.
	 *
	 * @return BackfillReadableCopies
	 */
	private function makeStep(): BackfillReadableCopies {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'course' => [['id' => 'course-rekenen', 'name' => 'Rekenen']],
			'cohort' => [['id' => 'cohort-6', 'name' => 'Groep 6']],
			'portfolio' => [['id' => 'portfolio-1', 'title' => 'Proeve meterkast', 'learnerRef' => 'profile-daan']],
			'learner-profile' => [['id' => 'profile-daan', 'givenName' => 'Daan', 'familyName' => 'Visser']],
			'grade-entry' => [
				['id' => 'grade-old', 'courseId' => 'course-rekenen', '@self' => ['owner' => 'someone']],
				['id' => 'grade-done', 'courseId' => 'course-rekenen', 'courseName' => 'Rekenen'],
			],
			'enrolment' => [['id' => 'enrolment-old', 'cohortId' => 'cohort-6']],
			'portfolio-share' => [['id' => 'share-old', 'portfolioId' => 'portfolio-1']],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		return new BackfillReadableCopies(
			objectService: $objectService,
			copies: new ReadableCopies(objectService: $objectService),
			logger: new NullLogger(),
		);
	}//end makeStep()

	/**
	 * Rows without copies get them; a row that has them is not saved.
	 *
	 * @return void
	 */
	public function testRowsWithoutCopiesGetThem(): void {
		$this->makeStep()->run($this->createMock(IOutput::class));

		$saved = [];
		foreach ($this->store->saves as $save) {
			$saved[$save['uuid']] = $save['object'];
		}

		self::assertSame(['grade-old', 'enrolment-old', 'share-old'], array_keys($saved));
		self::assertSame('Rekenen', $saved['grade-old']['courseName']);
		self::assertArrayNotHasKey('@self', $saved['grade-old']);
		self::assertSame('Groep 6', $saved['enrolment-old']['cohortName']);
		self::assertSame('Proeve meterkast', $saved['share-old']['portfolioTitle']);
		self::assertSame('Daan Visser', $saved['share-old']['learnerName']);
	}//end testRowsWithoutCopiesGetThem()

	/**
	 * A second run saves nothing.
	 *
	 * @return void
	 */
	public function testASecondRunSavesNothing(): void {
		$step = $this->makeStep();
		$step->run($this->createMock(IOutput::class));
		$first = count($this->store->saves);
		$step->run($this->createMock(IOutput::class));

		self::assertSame($first, count($this->store->saves));
	}//end testASecondRunSavesNothing()

	/**
	 * When OpenRegister cannot be read, nothing is written and the step ends.
	 *
	 * @return void
	 */
	public function testAFailedReadWritesNothing(): void {
		$step = $this->makeStep();
		$this->store->failReads = 'database gone';
		$step->run($this->createMock(IOutput::class));

		self::assertSame([], $this->store->saves);
	}//end testAFailedReadWritesNothing()
}//end class
