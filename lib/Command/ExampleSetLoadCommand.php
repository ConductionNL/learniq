<?php

/**
 * Learniq example-set load command
 *
 * `occ learniq:example-set:load <set>` loads one example set the way the
 * setup wizard does (the objects, then the portal and its site), and then
 * gives the staff the set's portal names a Nextcloud account with that
 * display name. Before this an example set could only be loaded through
 * the wizard, so a scripted spin-up had no way in.
 *
 * @category Command
 * @package  OCA\Learniq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Portal\ExamplePortalAccountGrants;
use OCA\Learniq\Portal\ExamplePortalProvisioner;
use OCA\Learniq\Service\SeedProfileService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ learniq:example-set:load <set> [--no-accounts]`.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */
class ExampleSetLoadCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param SeedProfileService         $sets    Imports the set and provisions its portal.
	 * @param ExamplePortalProvisioner   $portals Creates or names the set's accounts.
	 * @param ExamplePortalAccountGrants $grants  Gives the declared learners their portal account.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SeedProfileService $sets,
		private readonly ExamplePortalProvisioner $portals,
		private readonly ExamplePortalAccountGrants $grants,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description, argument and option.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-an-example-set-loads-from-occ
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:example-set:load')
			->setDescription(description: 'Load an example set with its portal and site; safe to run again, a second run adds nothing')
			->addArgument(name: 'set', mode: InputArgument::REQUIRED, description: 'The example set, for example po, vo, mbo or training')
			->addOption(
				name: 'no-accounts',
				mode: InputOption::VALUE_NONE,
				description: 'Do not create or name the Nextcloud accounts of the staff the portal names'
			);
	}//end configure()

	/**
	 * Load the set, its portal and, unless told not to, its accounts.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-an-example-set-loads-from-occ
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$set = (string)$input->getArgument('set');
		if ($this->sets->isKnown(profileId: $set) === false) {
			$output->writeln('<error>Unknown example set "' . $set . '".</error>');
			return self::FAILURE;
		}

		try {
			$result = $this->sets->install(profileId: $set);
		} catch (Throwable $exception) {
			$output->writeln('<error>Example set "' . $set . '" was not loaded: ' . $exception->getMessage() . '</error>');
			return self::FAILURE;
		}

		$output->writeln('Example set ' . $set . ': ' . (int)$result['objects'] . ' objects in the set, ' . (int)$result['skipped'] . ' skipped.');

		$exit = self::SUCCESS;
		if (isset($result['portal']) === true) {
			$output->writeln($this->portals->describe(result: $result['portal']));
			$known = array_merge(ExampleSetPortalCommand::SUCCESS_STATUSES, ['unmapped', 'portaliq-absent']);
			if (in_array($result['portal']['status'], $known, true) === false) {
				$exit = self::FAILURE;
			}
		}

		if ($input->getOption('no-accounts') !== true) {
			$accounts = $this->portals->provisionAccounts(profileId: $set);
			$output->writeln(
				'Accounts: ' . $accounts['created'] . ' created, ' . $accounts['named'] . ' named, '
				. $accounts['kept'] . ' kept, ' . $accounts['failed'] . ' failed.'
			);
			if ($accounts['failed'] > 0) {
				$exit = self::FAILURE;
			}

			// The learners' portal accounts, after their Nextcloud accounts exist (example-portal-install-steps).
			$output->writeln(self::describeGrants(grants: $this->grants->grant(profileId: $set)));
		}

		return $exit;
	}//end execute()

	/**
	 * One line that says what the portal-account step did.
	 *
	 * @param array{status: string, granted: int, kept: int, waiting: int, failed: int, reasons: array<int, string>} $grants The answer.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
	 */
	public static function describeGrants(array $grants): string {
		if ($grants['status'] === 'portaliq-too-old') {
			return 'Portal accounts: portaliq is too old to give a Nextcloud user a portal account; update portaliq and load the set again.';
		}

		if ($grants['status'] !== 'done') {
			return 'Portal accounts: none to give.';
		}

		$line = 'Portal accounts: ' . $grants['granted'] . ' given, ' . $grants['kept'] . ' kept, ' . $grants['failed'] . ' failed.';
		if ($grants['waiting'] > 0) {
			$line .= ' ' . $grants['waiting'] . ' wait for the portal\'s organisation: set it, then load the set again.';
		}

		if ($grants['reasons'] !== []) {
			$line .= ' Refused: ' . implode(', ', $grants['reasons']) . '.';
		}

		return $line;
	}//end describeGrants()
}//end class
