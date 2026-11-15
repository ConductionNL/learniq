<?php

/**
 * Learniq ExampleSetRemoveCommand golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Command
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

namespace OCA\Learniq\Tests\Unit\Command;

use OCA\Learniq\Command\ExampleSetRemoveCommand;
use OCA\Learniq\Service\SeedProfileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pins the exit code, the output and what the purge command received per
 * scenario against tests/Unit/Command/golden/ExampleSetRemove.json. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Command\ExampleSetRemoveCommand
 */
class ExampleSetRemoveGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/ExampleSetRemove.json';

	/**
	 * Every scenario equals the recorded golden.
	 *
	 * @return void
	 */
	public function testRemoveMatchesGolden(): void {
		$scenarios = [
			'apply' => [['id' => 'po', '--apply' => true], true, 0],
			'dry run' => [['id' => 'po'], true, 0],
			'purge fails' => [['id' => 'po', '--apply' => true], true, 3],
			'generated set' => [['id' => 'demo'], true, 0],
			'empty set' => [['id' => 'empty', '--apply' => true], true, 0],
			'no purge command' => [['id' => 'po'], false, 0],
		];
		$actual = [];
		foreach ($scenarios as $name => [$input, $withPurge, $purgeExit]) {
			$purge = null;
			$application = new Application();
			$application->setAutoExit(false);
			if ($withPurge === true) {
				$purge = self::fakePurge(exit: $purgeExit);
				$application->add($purge);
			}

			$application->add(new ExampleSetRemoveCommand($this->profiles()));
			$tester = new CommandTester($application->find('learniq:example-set:remove'));
			$exit = $tester->execute($input);
			$actual[$name] = ['exit' => $exit, 'display' => $tester->getDisplay(), 'purge' => $purge?->received];
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode((string)json_encode($actual), true));
	}//end testRemoveMatchesGolden()

	/**
	 * A purge command that records what it received.
	 *
	 * @param int $exit Its exit code.
	 *
	 * @return Command
	 */
	private static function fakePurge(int $exit): Command {
		return new class($exit) extends Command {
			/**
			 * What it received.
			 *
			 * @var array<string,mixed>
			 */
			public array $received = [];

			/**
			 * @param int $exitCode Its exit code.
			 */
			public function __construct(private int $exitCode) {
				parent::__construct();
			}

			/**
			 * @return void
			 */
			protected function configure(): void {
				$this->setName('openregister:objects:purge')
					->addArgument('uuid', (InputArgument::REQUIRED | InputArgument::IS_ARRAY))
					->addOption('force', null, InputOption::VALUE_NONE)
					->addOption('apply', null, InputOption::VALUE_NONE);
			}

			/**
			 * @param InputInterface  $input  Input.
			 * @param OutputInterface $output Output.
			 *
			 * @return int
			 */
			protected function execute(InputInterface $input, OutputInterface $output): int {
				$this->received = ['uuid' => $input->getArgument('uuid'), 'force' => $input->getOption('force'), 'apply' => $input->getOption('apply')];
				return $this->exitCode;
			}
		};
	}//end fakePurge()

	/**
	 * The seed profiles: po has three uuids, empty none, anything else throws.
	 *
	 * @return SeedProfileService
	 */
	private function profiles(): SeedProfileService {
		$profiles = $this->createMock(SeedProfileService::class);
		$profiles->method('uuidsFor')->willReturnCallback(
			static function (string $id): array {
				if ($id === 'empty') {
					return [];
				}

				if ($id !== 'po') {
					throw new RuntimeException('The generated set has no fixed uuids.');
				}

				return ['u-1', 'u-2', 'u-3'];
			}
		);
		return $profiles;
	}//end profiles()
}//end class
