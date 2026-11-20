<?php

/**
 * Learniq AbstractBpvPortalInviteCommand
 *
 * What the occ commands that invite a workplace trainer or an external
 * assessor share: the invitation service, the run and its report.
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
 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Kernel\Contracts\BpvPortalInvitationInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Invites the person a subclass names to their BPV or exam portal and
 * reports the outcome. A subclass declares the command, the role and the
 * argument that carries the person's uuid.
 *
 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
 */
abstract class AbstractBpvPortalInviteCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param BpvPortalInvitationInterface $invitations Provisions the account and writes the claim.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BpvPortalInvitationInterface $invitations,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The portal role the person is invited as.
	 *
	 * @return string
	 */
	abstract protected function role(): string;

	/**
	 * The argument that carries the uuid of the person the school created.
	 *
	 * @return string
	 */
	abstract protected function personArgument(): string;

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
			role: $this->role(),
			personRef: (string)$input->getArgument($this->personArgument()),
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
