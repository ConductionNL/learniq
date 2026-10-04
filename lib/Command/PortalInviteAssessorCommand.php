<?php

/**
 * Learniq PortalInviteAssessorCommand
 *
 * `occ learniq:portal:invite-assessor <externalAssessorRef> <organisation> [email]`.
 *
 * The uuid must name a person the school already created; the invitation
 * never mints one. Without the address argument the one on that row is used.
 *
 * @category Command
 * @package  OCA\Learniq\Command
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
 *
 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Portal\BpvPortalInvitation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Invites one person to their portal and writes the claim it is scoped by.
 *
 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
 */
class PortalInviteAssessorCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param BpvPortalInvitation $invitations Provisions the account and writes the claim.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BpvPortalInvitation $invitations,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:portal:invite-assessor')
			->setDescription(description: 'Invite an external assessor the school already created to the exam portal')
			->addArgument(name: 'externalAssessorRef', mode: InputArgument::REQUIRED, description: 'The external assessor uuid the school created')
			->addArgument(name: 'organisation', mode: InputArgument::REQUIRED, description: 'The portal organisation slug')
			->addArgument(name: 'email', mode: InputArgument::OPTIONAL, description: 'An address to use instead of the one on the record', default: '');
	}//end configure()

	/**
	 * Run the invitation.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->invitations->invite(
			role: 'assessor',
			personRef: (string)$input->getArgument('externalAssessorRef'),
			organisation: (string)$input->getArgument('organisation'),
			email: (string)$input->getArgument('email')
		);

		if ($result['status'] !== 'invited') {
			$output->writeln('<error>Not invited: ' . ($result['reason'] ?? 'unknown') . '</error>');
			return self::FAILURE;
		}

		$output->writeln('Invited. Portal account: ' . ($result['subjectRef'] ?? ''));
		return self::SUCCESS;
	}//end execute()
}//end class
