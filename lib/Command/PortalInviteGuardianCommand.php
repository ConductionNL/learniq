<?php

/**
 * Learniq occ command: invite a guardian to the parent portal.
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
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Portal\GuardianPortalInvitation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ learniq:portal:invite-guardian <guardianRef> <email> <organisation>`.
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */
class PortalInviteGuardianCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param GuardianPortalInvitation $invitations Provisions and links the account.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GuardianPortalInvitation $invitations,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md#requirement-the-school-links-a-guardian-to-the-parent-portal-req-pid-004
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:portal:invite-guardian')
			->setDescription(description: 'Invite a guardian to the parent portal with an email address the school verified')
			->addArgument(name: 'guardianRef', mode: InputArgument::REQUIRED, description: 'The guardian\'s learner profile uuid')
			->addArgument(name: 'email', mode: InputArgument::REQUIRED, description: 'The email address the school verified with the guardian')
			->addArgument(name: 'organisation', mode: InputArgument::REQUIRED, description: 'The portal organisation slug');
	}//end configure()

	/**
	 * Run the invitation.
	 *
	 * @param InputInterface $input The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md#requirement-the-school-links-a-guardian-to-the-parent-portal-req-pid-004
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->invitations->invite(
			guardianRef: (string)$input->getArgument('guardianRef'),
			email: (string)$input->getArgument('email'),
			organisation: (string)$input->getArgument('organisation')
		);

		if ($result['status'] !== 'invited') {
			$output->writeln('<error>Not invited: ' . ($result['reason'] ?? 'unknown') . '</error>');
			return self::FAILURE;
		}

		$output->writeln('Invited. Portal account: ' . ($result['subjectRef'] ?? ''));
		return self::SUCCESS;
	}//end execute()
}//end class
