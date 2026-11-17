<?php

/**
 * Learniq PortalInviteTrainerCommand
 *
 * `occ learniq:portal:invite-trainer <praktijkopleiderRef> <organisation> [email]`.
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

use Symfony\Component\Console\Input\InputArgument;

/**
 * Invites one person to their portal and writes the claim it is scoped by.
 *
 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
 */
class PortalInviteTrainerCommand extends AbstractBpvPortalInviteCommand {

	/**
	 * Name, description and arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invite-a-trainer-and-an-assessor/specs/portal-identity/spec.md#requirement-a-school-invites-a-trainer-or-an-assessor-it-already-created
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:portal:invite-trainer')
			->setDescription(description: 'Invite a workplace trainer the school already created to the BPV portal')
			->addArgument(name: 'praktijkopleiderRef', mode: InputArgument::REQUIRED, description: 'The praktijkopleider uuid the school created')
			->addArgument(name: 'organisation', mode: InputArgument::REQUIRED, description: 'The portal organisation slug')
			->addArgument(name: 'email', mode: InputArgument::OPTIONAL, description: 'An address to use instead of the one on the record', default: '');
	}//end configure()

	/**
	 * The portal role the person is invited as.
	 *
	 * @return string
	 */
	protected function role(): string {
		return 'trainer';
	}//end role()

	/**
	 * The argument that carries the person's uuid.
	 *
	 * @return string
	 */
	protected function personArgument(): string {
		return 'praktijkopleiderRef';
	}//end personArgument()
}//end class
