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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ learniq:portal:invite-guardian <guardianRef> <email> <organisation>`.
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */
class PortalInviteGuardianCommand extends Command {

	/**
	 * What the command says about the invitation mail.
	 */
	private const MAIL_LINES = [
		GuardianPortalInvitation::MAIL_SENT => 'The portal mailed the guardian a link. It works once, for seven days.',
		GuardianPortalInvitation::MAIL_NOT_SENT => '<comment>The invitation mail did not leave. Check the mail settings and invite again.</comment>',
		GuardianPortalInvitation::MAIL_UNAVAILABLE => '<comment>No invitation was sent: this portal does not make one.'
			. ' The guardian is still linked on the verified address.</comment>',
	];

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
	 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:portal:invite-guardian')
			->setDescription(description: 'Invite a guardian to the parent portal with an email address the school verified')
			->addArgument(name: 'guardianRef', mode: InputArgument::REQUIRED, description: 'The guardian\'s learner profile uuid')
			->addArgument(name: 'email', mode: InputArgument::REQUIRED, description: 'The email address the school verified with the guardian')
			->addArgument(name: 'organisation', mode: InputArgument::REQUIRED, description: 'The portal organisation slug')
			->addOption(name: 'letter', mode: InputOption::VALUE_NONE, description: 'Send no mail and print a one-time code for a paper letter');
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
	 * @spec openspec/changes/portal-guardian-invitation-mail/specs/portal-identity/spec.md
	 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->invitations->invite(
			guardianRef: (string)$input->getArgument('guardianRef'),
			email: (string)$input->getArgument('email'),
			organisation: (string)$input->getArgument('organisation'),
			channel: $this->channel(input: $input)
		);

		if ($result['status'] !== 'invited') {
			$output->writeln('<error>Not invited: ' . ($result['reason'] ?? 'unknown') . '</error>');
			return self::FAILURE;
		}

		$output->writeln('Invited. Portal account: ' . ($result['subjectRef'] ?? ''));
		if (($result['invitation'] ?? '') === GuardianPortalInvitation::LETTER_CODE) {
			$output->writeln('Code for the letter: ' . ($result['code'] ?? ''));
			$output->writeln('It works once, until ' . ($result['expiresAt'] ?? '') . '. The guardian signs in and types it under "My account".');
			return self::SUCCESS;
		}

		$output->writeln(self::MAIL_LINES[($result['invitation'] ?? '')] ?? self::MAIL_LINES[GuardianPortalInvitation::MAIL_UNAVAILABLE]);
		return self::SUCCESS;
	}//end execute()

	/**
	 * The channel the command was asked for.
	 *
	 * @param InputInterface $input The input.
	 *
	 * @return string
	 */
	private function channel(InputInterface $input): string {
		if ($input->getOption('letter') === true) {
			return GuardianPortalInvitation::CHANNEL_LETTER;
		}

		return GuardianPortalInvitation::CHANNEL_MAIL;
	}//end channel()
}//end class
