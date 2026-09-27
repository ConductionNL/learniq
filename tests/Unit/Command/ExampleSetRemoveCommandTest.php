<?php

/**
 * Learniq example-set removal command tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
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
 * The command hands exactly the set's uuids to OpenRegister's purge command.
 */
class ExampleSetRemoveCommandTest extends TestCase {

	/**
	 * The uuids the fixture set declares, last-loaded first.
	 *
	 * @var string[]
	 */
	private const UUIDS = [
		'ee010003-0000-4000-8000-000000000001',
		'ee010002-0000-4000-8000-000000000001',
		'ee010001-0000-4000-8000-000000000001',
	];

	/**
	 * A stand-in for `openregister:objects:purge` that records what it got.
	 *
	 * @return Command
	 */
	private static function fakePurge(): Command {
		return new class extends Command {
			/**
			 * The arguments of the last run.
			 *
			 * @var array<string, mixed>
			 */
			public array $received = [];

			/**
			 * Declare the same signature as OpenRegister's purge command.
			 *
			 * @return void
			 */
			protected function configure(): void {
				$this->setName('openregister:objects:purge')
					->addArgument('uuid', (InputArgument::REQUIRED | InputArgument::IS_ARRAY))
					->addOption('force', null, InputOption::VALUE_NONE)
					->addOption('apply', null, InputOption::VALUE_NONE);
			}//end configure()

			/**
			 * Record the input.
			 *
			 * @param InputInterface  $input  Console input.
			 * @param OutputInterface $output Console output.
			 *
			 * @return int
			 */
			protected function execute(InputInterface $input, OutputInterface $output): int {
				$this->received = [
					'uuid'  => $input->getArgument('uuid'),
					'force' => $input->getOption('force'),
					'apply' => $input->getOption('apply'),
				];
				return 0;
			}//end execute()
		};
	}//end fakePurge()

	/**
	 * A tester for the command, with or without a purge command available.
	 *
	 * @param SeedProfileService $profiles The profile service double.
	 * @param Command|null       $purge    The purge stand-in, or null for none.
	 *
	 * @return CommandTester
	 */
	private static function tester(SeedProfileService $profiles, ?Command $purge): CommandTester {
		$application = new Application();
		$application->setAutoExit(false);
		if ($purge !== null) {
			$application->add($purge);
		}

		$command = new ExampleSetRemoveCommand($profiles);
		$application->add($command);

		return new CommandTester($application->find('learniq:example-set:remove'));
	}//end tester()

	/**
	 * A profile service that knows the fixture set.
	 *
	 * @return SeedProfileService
	 */
	private function profiles(): SeedProfileService {
		$profiles = $this->createMock(SeedProfileService::class);
		$profiles->method('uuidsFor')->willReturnCallback(
			static function (string $id): array {
				if ($id !== 'po') {
					throw new RuntimeException('The generated set has no fixed uuids.');
				}

				return self::UUIDS;
			}
		);

		return $profiles;
	}//end profiles()

	/**
	 * With --apply, the purge command gets every uuid, --force and --apply.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-removing-the-primary-school-set
	 */
	public function testApplyHandsEveryUuidWithForceAndApply(): void {
		$purge  = self::fakePurge();
		$tester = self::tester($this->profiles(), $purge);

		self::assertSame(0, $tester->execute(['id' => 'po', '--apply' => true]));
		self::assertSame(self::UUIDS, $purge->received['uuid']);
		self::assertTrue($purge->received['force']);
		self::assertTrue($purge->received['apply']);
	}//end testApplyHandsEveryUuidWithForceAndApply()

	/**
	 * Without --apply the purge runs as a dry run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-a-dry-run-changes-nothing
	 */
	public function testWithoutApplyThePurgeIsADryRun(): void {
		$purge  = self::fakePurge();
		$tester = self::tester($this->profiles(), $purge);

		self::assertSame(0, $tester->execute(['id' => 'po']));
		self::assertFalse($purge->received['apply']);
		self::assertStringContainsString('dry run', $tester->getDisplay());
	}//end testWithoutApplyThePurgeIsADryRun()

	/**
	 * The generated set is refused with the service's reason, and nothing
	 * reaches the purge command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-the-generated-set-is-refused
	 */
	public function testTheGeneratedSetIsRefused(): void {
		$purge  = self::fakePurge();
		$tester = self::tester($this->profiles(), $purge);

		self::assertSame(1, $tester->execute(['id' => 'demo', '--apply' => true]));
		self::assertSame([], $purge->received);
		self::assertStringContainsString('fixed uuids', $tester->getDisplay());
	}//end testTheGeneratedSetIsRefused()

	/**
	 * Without OpenRegister's purge command the command says so and fails.
	 *
	 * @return void
	 */
	public function testAMissingPurgeCommandIsReported(): void {
		$tester = self::tester($this->profiles(), null);

		self::assertSame(1, $tester->execute(['id' => 'po', '--apply' => true]));
		self::assertStringContainsString('openregister:objects:purge', $tester->getDisplay());
	}//end testAMissingPurgeCommandIsReported()
}//end class
