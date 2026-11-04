<?php

/**
 * Learniq PortalEmployerTenancyReportCommand
 *
 * `occ learniq:portal:employer-tenancy-report` lists the employer portal
 * accounts whose company claim names a company outside the organisation of
 * the account's portal. Such an account reads and changes nothing of that
 * company: portaliq asks learniq before the claim scopes anything, and the
 * employer's writes check the same. The report says which accounts exist, so
 * their owners can decide what to do with them. It changes nothing.
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Portal\EmployerAccounts;
use OCA\Learniq\Portal\EmployerCompanyTenancy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Reports the employer accounts whose company lies outside their portal's organisation.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */
class PortalEmployerTenancyReportCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param EmployerAccounts       $accounts Lists the employer accounts.
	 * @param EmployerCompanyTenancy $tenancy  Checks each account's company.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EmployerAccounts $accounts,
		private readonly EmployerCompanyTenancy $tenancy,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and the organisation option.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:portal:employer-tenancy-report')
			->setDescription(description: 'List the employer portal accounts whose company lies outside their portal\'s organisation. Changes nothing.')
			->addOption(
				name: 'organisation',
				mode: InputOption::VALUE_REQUIRED,
				description: 'Only the accounts of this portal organisation slug',
				default: ''
			);
	}//end configure()

	/**
	 * Print one line per affected account, then the count.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int FAILURE when the accounts cannot be read, else SUCCESS.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$accounts = $this->accounts->withCompany();
		} catch (Throwable $exception) {
			$output->writeln('<error>The employer accounts could not be read: ' . $exception->getMessage() . '</error>');
			return self::FAILURE;
		}

		$only = trim((string)$input->getOption('organisation'));
		if ($only !== '') {
			$accounts = array_values(array_filter($accounts, static fn (array $account): bool => $account['organisation'] === $only));
		}

		$affected = 0;
		foreach ($accounts as $account) {
			$verdict = $this->tenancy->verdict(organisationRef: $account['organisationRef'], organisation: $account['organisation']);
			if ($verdict !== EmployerCompanyTenancy::HOLDS) {
				$affected++;
				$output->writeln(implode("\t", [$account['subjectRef'], $account['organisation'], $account['organisationRef'], $verdict]));
			}
		}

		$output->writeln(sprintf('%d of %d employer accounts name a company outside their portal\'s organisation.', $affected, count($accounts)));
		$this->noteTheCap(output: $output);

		return self::SUCCESS;
	}//end execute()

	/**
	 * Say so when the listing stopped at the account cap, so the count is not read as complete.
	 *
	 * @param OutputInterface $output The output.
	 *
	 * @return void
	 */
	private function noteTheCap(OutputInterface $output): void {
		if ($this->accounts->stoppedAtCap() === true) {
			$output->writeln(sprintf('The listing stopped at %d accounts; there may be more that this report did not check.', EmployerAccounts::CAP));
		}
	}//end noteTheCap()
}//end class
