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
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

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
	 * A user manager that knows one teacher's display name.
	 *
	 * @return IUserManager
	 */
	private function users(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => ['po-leerkracht-09' => 'Meester Daan'][$uid] ?? null
		);

		return $users;
	}//end users()

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
			'teacher-availability' => [
				['id' => 'availability-old', 'teacherId' => 'po-leerkracht-09', 'blocks' => []],
				['id' => 'availability-done', 'teacherId' => 'po-leerkracht-09', 'teacherName' => 'Meester Daan', 'blocks' => []],
			],
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
			copies: new ReadableCopies(objectService: $objectService, users: $this->users()),
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

		// The profile gets its name line (employer-portal-audience).
		self::assertSame(['grade-old', 'enrolment-old', 'share-old', 'availability-old', 'profile-daan'], array_keys($saved));
		self::assertSame('Daan Visser', $saved['profile-daan']['fullName']);
		self::assertSame('Meester Daan', $saved['availability-old']['teacherName']);
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
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesItsWork(): void {
		self::assertStringContainsString('readable', $this->makeStep()->getName());
	}//end testTheStepNamesItsWork()

	/**
	 * Plain array rows are read; a row without an id is skipped; a save that
	 * fails is counted as failed and the next row is still written.
	 *
	 * @return void
	 */
	public function testArrayRowsAndFailedSaves(): void {
		$saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config): array {
				$schema = $config['filters']['schema'];
				if ($schema === 'cohort') {
					return [['id' => 'cohort-6', 'name' => 'Groep 6']];
				}

				if ($schema !== 'enrolment' || ($config['offset'] ?? 0) > 0) {
					return [];
				}

				return [
					['cohortId' => 'cohort-6'],
					['id' => 'enrolment-broken', 'cohortId' => 'cohort-6'],
					['id' => 'enrolment-ok', 'cohortId' => 'cohort-6'],
				];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use (&$saved) {
				if ($uuid === 'enrolment-broken') {
					throw new RuntimeException('locked');
				}

				$saved[$uuid] = $object['cohortName'];
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
			$messages[] = $message;
		});

		(new BackfillReadableCopies(objectService: $objectService, copies: new ReadableCopies(objectService: $objectService, users: $this->users()), logger: new NullLogger()))->run($output);

		self::assertSame(['enrolment-ok' => 'Groep 6'], $saved);
		self::assertContains('BackfillReadableCopies enrolment: 1 stamped, 1 failed, of 3 scanned.', $messages);
	}//end testArrayRowsAndFailedSaves()

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
