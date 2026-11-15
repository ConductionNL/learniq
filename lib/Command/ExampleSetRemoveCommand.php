<?php

/**
 * Learniq example-set removal command.
 *
 * `occ learniq:example-set:remove <id> [--apply]` removes one loaded example
 * set by handing exactly the fixed uuids its descriptor declares to
 * OpenRegister's `openregister:objects:purge --force`.
 *
 * 🔴 WHY IT DELEGATES INSTEAD OF DELETING. A school set holds LearnerProfile,
 * AttendanceRecord and DossierNote rows, and all three schemas carry
 * `x-openregister-archival`. OpenRegister refuses every delete of an archival
 * record except the retention cron's, and keeps one deliberate exit for "a
 * test fixture" or a record created in error: its purge command, CLI-only
 * because shell access is a real authorization boundary. Example data is a
 * fixture, so it leaves the same way, and this app adds no HTTP delete and
 * never borrows the cron's retention flag.
 *
 * @category Command
 * @package  OCA\Learniq\Command
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Kernel\Contracts\SeedProfileServiceInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes one example set through OpenRegister's purge command.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
 */
class ExampleSetRemoveCommand extends Command {
	/**
	 * The OpenRegister command that destroys rows by uuid.
	 */
	private const PURGE_COMMAND = 'openregister:objects:purge';

	/**
	 * Constructor.
	 *
	 * @param SeedProfileServiceInterface $seedProfiles Resolves a set's fixed uuids.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SeedProfileServiceInterface $seedProfiles,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:example-set:remove')
			->setDescription(description: 'Remove a loaded example set (po, vo, mbo, he, corporate or training)')
			->addArgument(
				name: 'id',
				mode: InputArgument::REQUIRED,
				description: 'The example set to remove'
			)
			->addOption(
				name: 'apply',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Actually remove the objects. Without it the command reports what it would remove'
			);
	}//end configure()

	/**
	 * Hand the set's uuids to OpenRegister's purge command.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int The purge command's exit code; 1 when the set cannot be removed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-loaded-set-can-be-removed-exactly
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$profileId = (string)$input->getArgument('id');
		$apply     = (bool)$input->getOption('apply');

		try {
			$uuids = $this->seedProfiles->uuidsFor(profileId: $profileId);
		} catch (RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		if ($uuids === []) {
			$output->writeln('<comment>The example set "' . $profileId . '" declares no objects; nothing to remove.</comment>');
			return 0;
		}

		$purge = $this->purgeCommand(output: $output);
		if ($purge === null) {
			return 1;
		}

		$output->writeln(self::handOver(profileId: $profileId, uuids: $uuids, apply: $apply));

		return $purge->run(new ArrayInput(self::purgeArguments(uuids: $uuids, apply: $apply)), $output);
	}//end execute()

	/**
	 * OpenRegister's purge command, or null (reported) when it is not available.
	 *
	 * @param OutputInterface $output The output.
	 *
	 * @return Command|null
	 */
	private function purgeCommand(OutputInterface $output): ?Command {
		$application = $this->getApplication();
		try {
			if ($application === null) {
				throw new CommandNotFoundException(self::PURGE_COMMAND);
			}

			return $application->find(self::PURGE_COMMAND);
		} catch (CommandNotFoundException $e) {
			$output->writeln(
				'<error>OpenRegister\'s ' . self::PURGE_COMMAND . ' command is not available; is OpenRegister installed and up to date?</error>'
			);
			return null;
		}
	}//end purgeCommand()

	/**
	 * The line that says what is handed to the purge command.
	 *
	 * @param string       $profileId The example set.
	 * @param list<string> $uuids     Its objects.
	 * @param bool         $apply     Whether the purge applies or is a dry run.
	 *
	 * @return string
	 */
	private static function handOver(string $profileId, array $uuids, bool $apply): string {
		$mode = ' (dry run)';
		if ($apply === true) {
			$mode = ' --apply';
		}

		return sprintf('Example set "%s": %d object(s), handed to %s --force%s.', $profileId, count($uuids), self::PURGE_COMMAND, $mode);
	}//end handOver()

	/**
	 * The purge command's arguments.
	 *
	 * @param list<string> $uuids The objects.
	 * @param bool         $apply Whether the purge applies.
	 *
	 * @return array<string,mixed>
	 */
	private static function purgeArguments(array $uuids, bool $apply): array {
		$arguments = ['uuid' => $uuids, '--force' => true];
		if ($apply === true) {
			$arguments['--apply'] = true;
		}

		return $arguments;
	}//end purgeArguments()
}//end class
