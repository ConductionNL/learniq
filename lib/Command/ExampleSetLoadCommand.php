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
	 * @param SeedProfileService       $sets    Imports the set and provisions its portal.
	 * @param ExamplePortalProvisioner $portals Creates or names the set's accounts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SeedProfileService $sets,
		private readonly ExamplePortalProvisioner $portals,
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
		}

		return $exit;
	}//end execute()
}//end class
