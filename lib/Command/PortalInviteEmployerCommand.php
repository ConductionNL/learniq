<?php

/**
 * Learniq PortalInviteEmployerCommand
 *
 * `occ learniq:portal:invite-employer <organisationRef> <organisation> [email]`.
 *
 * The uuid must name a client company the institute already created; the
 * invitation never mints one. Without the address argument the company's
 * contact e-mail is used.
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Portal\EmployerPortalInvitation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Invites a company's contact person to the employer's portal and writes its claims.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */
class PortalInviteEmployerCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param EmployerPortalInvitation $invitations Provisions the account and writes the claims.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EmployerPortalInvitation $invitations,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:portal:invite-employer')
			->setDescription(description: 'Invite the contact person of a client company the institute already created to the employer portal')
			->addArgument(name: 'organisationRef', mode: InputArgument::REQUIRED, description: 'The client-organisation uuid the institute created')
			->addArgument(name: 'organisation', mode: InputArgument::REQUIRED, description: 'The portal organisation slug')
			->addArgument(name: 'email', mode: InputArgument::OPTIONAL, description: 'An address to use instead of the company contact e-mail', default: '');
	}//end configure()

	/**
	 * Run the invitation.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->invitations->invite(
			organisationRef: (string)$input->getArgument('organisationRef'),
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
