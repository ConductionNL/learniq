<?php

/**
 * Learniq example account provisioner
 *
 * The designed portals name their staff: "Meester Daan", "juf Esra",
 * "Petra Bakker". Portaliq shows a staff user id as that user's Nextcloud
 * display name, so without an account the portal printed "po-leerkracht-09".
 * This class gives the accounts an example set's declaration names a display
 * name, creating a missing account with a random password nobody knows.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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

namespace OCA\Learniq\Portal;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates or names the accounts of one example set.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */
class ExampleAccountProvisioner {

	/**
	 * Constructor.
	 *
	 * @param IUserManager    $userManager  Finds and creates accounts.
	 * @param IGroupManager   $groupManager Adds an account to its role group.
	 * @param ISecureRandom   $random       The password of a new account.
	 * @param LoggerInterface $logger       Records what happened.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create or name every declared account.
	 *
	 * - A missing account is created with the display name and a random
	 *   72-character password; an administrator sets a real one or sends an
	 *   invitation. The password is never logged or returned.
	 * - An existing account gets the display name only when it has none of
	 *   its own (its display name is its user id). A name somebody chose is
	 *   kept.
	 * - The account joins each declared group that exists. A group is never
	 *   created here, and membership is never removed.
	 *
	 * @param array<int, array{userId?: string, displayName?: string, groups?: array<int, string>}> $accounts The declared accounts.
	 *
	 * @return array{created: int, named: int, kept: int, failed: int}
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-the-staff-a-portal-names-have-accounts-with-those-names
	 */
	public function provision(array $accounts): array {
		$counts = ['created' => 0, 'named' => 0, 'kept' => 0, 'failed' => 0];
		foreach ($accounts as $account) {
			$userId      = trim((string)($account['userId'] ?? ''));
			$displayName = trim((string)($account['displayName'] ?? ''));
			if ($userId === '' || $displayName === '') {
				continue;
			}

			try {
				$outcome = $this->one(userId: $userId, displayName: $displayName, groups: (array)($account['groups'] ?? []));
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[ExampleAccountProvisioner] account "{user}" could not be written: {msg}',
					['user' => $userId, 'msg' => $exception->getMessage()]
				);
				$outcome = 'failed';
			}

			$counts[$outcome]++;
		}

		return $counts;
	}//end provision()

	/**
	 * Create or name one account and put it in its groups.
	 *
	 * @param string             $userId      The user id.
	 * @param string             $displayName The display name.
	 * @param array<int, string> $groups      The role groups.
	 *
	 * @return string `created`, `named` or `kept`.
	 */
	private function one(string $userId, string $displayName, array $groups): string {
		$user    = $this->userManager->get($userId);
		$outcome = 'kept';
		if ($user === null) {
			$user = $this->userManager->createUser(
				$userId,
				$this->random->generate(72, ISecureRandom::CHAR_ALPHANUMERIC . ISecureRandom::CHAR_SYMBOLS)
			);
			if ($user === false) {
				throw new \RuntimeException('the user backend refused to create it');
			}

			$user->setDisplayName($displayName);
			$outcome = 'created';
		} else if (in_array(trim($user->getDisplayName()), ['', $userId], true) === true) {
			$user->setDisplayName($displayName);
			$outcome = 'named';
		}

		foreach ($groups as $groupId) {
			$group = $this->groupManager->get((string)$groupId);
			if ($group !== null && $group->inGroup($user) === false) {
				$group->addUser($user);
			}
		}

		return $outcome;
	}//end one()
}//end class
