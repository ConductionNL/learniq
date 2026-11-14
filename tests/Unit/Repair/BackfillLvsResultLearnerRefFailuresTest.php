<?php
/**
 * Unit tests for BackfillLvsResultLearnerRef's failure path: a save OpenRegister refuses is
 * logged by row and counted as failed, and the walk goes on.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillLvsResultLearnerRef;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Repair\BackfillLvsResultLearnerRef
 * @uses   \OCA\Learniq\Service\LearnerRefResolver
 * @uses   \OCA\Learniq\Repair\Support\RepairRowWalk
 * @uses   \OCA\Learniq\Repair\Support\RepairTally
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class BackfillLvsResultLearnerRefFailuresTest extends TestCase {

	/**
	 * The step over a register-faithful store whose saves of the given uuid fail.
	 *
	 * @param RegisterFaithfulStore $store   The store.
	 * @param array<int, string>    $failing Uuids whose save throws.
	 * @param LoggerInterface       $logger  The logger.
	 *
	 * @return BackfillLvsResultLearnerRef
	 */
	private function step(RegisterFaithfulStore $store, array $failing, LoggerInterface $logger): BackfillLvsResultLearnerRef {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($store, $failing) {
				if (in_array($uuid, $failing, true) === true) {
					throw new \RuntimeException('save refused');
				}

				return $store->save((string)$schema, $object, $uuid);
			}
		);

		return new BackfillLvsResultLearnerRef(
			objectService: $objectService,
			learnerRefs: new LearnerRefResolver(objectService: $objectService),
			logger: $logger,
		);
	}//end step()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testName(): void {
		self::assertSame(
			'Link existing LVS results to the learner profile of their pupil',
			$this->step(store: new RegisterFaithfulStore(), failing: [], logger: $this->createMock(LoggerInterface::class))->getName()
		);
	}//end testName()

	/**
	 * A refused save is logged with its row and counted as failed; the next row is still stamped.
	 *
	 * @return void
	 */
	public function testARefusedSaveIsLoggedAndCountedAsFailed(): void {
		$store = new RegisterFaithfulStore();
		$store->rows['learner-profile'] = [['id' => 'lp-1', 'ncUserId' => 'pupil-1']];
		$store->rows['lvs-result'] = [
			['id' => 'lr-1', 'learnerId' => 'pupil-1'],
			['id' => 'lr-2', 'learnerId' => 'pupil-1'],
		];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('[BackfillLvsResultLearnerRef] Could not stamp LVS result {id}: {msg}', ['id' => 'lr-1', 'msg' => 'save refused']);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())
			->method('info')
			->with('BackfillLvsResultLearnerRef: 1 stamped, 0 without a learner profile, 1 failed, of 2 scanned.');

		$this->step(store: $store, failing: ['lr-1'], logger: $logger)->run($output);

		self::assertSame(['lr-2'], array_column($store->saves, 'uuid'));
		self::assertSame('lp-1', $store->saves[0]['object']['learnerRef']);
	}//end testARefusedSaveIsLoggedAndCountedAsFailed()
}//end class
