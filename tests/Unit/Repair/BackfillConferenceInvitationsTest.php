<?php
/**
 * Unit tests for BackfillConferenceInvitations' failure handling: OpenRegister
 * unavailable, one round failing, and entity rows.
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

use OCA\Learniq\Repair\BackfillConferenceInvitations;
use OCA\Learniq\Service\ConferenceInvitations;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Repair\BackfillConferenceInvitations
 * @uses   \OCA\Learniq\Service\ConferenceInvitations
 */
class BackfillConferenceInvitationsTest extends TestCase {

	/**
	 * The step over one ObjectService double, as the container wires it.
	 *
	 * @param ObjectService   $objects The ObjectService double.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return BackfillConferenceInvitations
	 */
	private function step(ObjectService $objects, LoggerInterface $logger): BackfillConferenceInvitations {
		return new BackfillConferenceInvitations(objectService: $objects, invitations: new ConferenceInvitations(objectService: $objects), logger: $logger);
	}//end step()

	/**
	 * A repair output expecting exactly one info line.
	 *
	 * @param string $expected The line.
	 *
	 * @return IOutput
	 */
	private function expectInfo(string $expected): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with($expected);

		return $output;
	}//end expectInfo()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testName(): void {
		self::assertSame(
			'Write one conference invitation per invited child for the rounds open for booking',
			$this->step(objects: $this->createMock(ObjectService::class), logger: $this->createMock(LoggerInterface::class))->getName()
		);
	}//end testName()

	/**
	 * When the open rounds cannot be read the step stops early, logs why, and reports nothing written.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterStopsEarly(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::once())->method('findAll')->willThrowException(new \RuntimeException('register missing'));
		$objects->expects(self::never())->method('saveObject');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('[BackfillConferenceInvitations] Stopped early: {msg}', ['msg' => 'register missing']);

		$this->step(objects: $objects, logger: $logger)
			->run($this->expectInfo('BackfillConferenceInvitations: 0 invitation(s) written for 0 open round(s).'));
	}//end testAnUnreadableRegisterStopsEarly()

	/**
	 * A round given as an entity is read; when its sync fails it is logged by id and the walk goes on.
	 *
	 * @return void
	 */
	public function testAFailingRoundIsLoggedAndTheWalkGoesOn(): void {
		$queries = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = [], mixed ...$rest) use (&$queries): array {
				$queries[] = $config['filters']['schema'] ?? '';
				if (count($queries) === 1) {
					return [
						OrEntityFactory::make(['id' => 'r-1', 'title' => 'Ronde 1'], 'conference-round'),
						['title' => 'Ronde zonder id'],
					];
				}

				throw new \RuntimeException('times unavailable');
			}
		);
		$objects->expects(self::never())->method('saveObject');

		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->step(objects: $objects, logger: $logger)
			->run($this->expectInfo('BackfillConferenceInvitations: 0 invitation(s) written for 2 open round(s).'));

		self::assertSame(
			[['[BackfillConferenceInvitations] Round {round} failed: {msg}', ['round' => 'r-1', 'msg' => 'times unavailable']]],
			$warnings
		);
		self::assertSame('conference-round', $queries[0]);
	}//end testAFailingRoundIsLoggedAndTheWalkGoesOn()
}//end class
