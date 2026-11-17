<?php

/**
 * Learniq Portaliq Staff Messages
 *
 * The staff side of portaliq's guardian messaging, as its staff message
 * endpoints use it: the threads a member of staff takes part in, the
 * messages of one of them and a reply. Portaliq decides participation; this
 * adapter only reaches its messaging service when portaliq is installed.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Pupil
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Pupil;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Portaliq's staff message calls, or null when portaliq cannot answer.
 *
 * @spec openspec/changes/learner-list-and-page-follow-the-boards/specs/pupil-dossier/spec.md#requirement-the-conversation-with-the-guardians-is-on-the-pupils-page
 */
class PortaliqStaffMessages {

	/**
	 * Portaliq's app id.
	 */
	public const APP_ID = 'portaliq';

	/**
	 * The messaging service portaliq's staff message endpoints run on.
	 */
	public const MESSAGING_CLASS = 'OCA\\Portaliq\\Service\\Messaging\\GuardianMessagingLeafInterface';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Whether portaliq is installed.
	 * @param ContainerInterface $container  Server container, for portaliq's messaging service.
	 * @param LoggerInterface    $logger     PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The threads a member of staff takes part in.
	 *
	 * @param string $staffRef The staff member's user id.
	 *
	 * @return array<int, array<string, mixed>>|null The threads, or null when portaliq cannot answer.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public function threads(string $staffRef): ?array {
		$messaging = $this->messaging();
		if ($messaging === null) {
			return null;
		}

		try {
			return array_values(array_filter((array)$messaging->listThreads($staffRef, true), 'is_array'));
		} catch (Throwable $exception) {
			$this->logger->warning('[PortaliqStaffMessages] threads could not be read: {msg}', ['msg' => $exception->getMessage()]);
			return null;
		}
	}//end threads()

	/**
	 * The messages of one thread, for a participant.
	 *
	 * @param string $threadId The thread.
	 * @param string $staffRef The staff member's user id.
	 *
	 * @return array<int, array<string, mixed>>|null The messages, or null for no participant or no answer.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public function messages(string $threadId, string $staffRef): ?array {
		$messaging = $this->messaging();
		if ($messaging === null) {
			return null;
		}

		try {
			$messages = $messaging->listMessages($threadId, $staffRef, true);
		} catch (Throwable $exception) {
			$this->logger->warning('[PortaliqStaffMessages] messages could not be read: {msg}', ['msg' => $exception->getMessage()]);
			return null;
		}

		if (is_array($messages) === false) {
			return null;
		}

		return array_values(array_filter($messages, 'is_array'));
	}//end messages()

	/**
	 * Post a reply in a thread; portaliq refuses one from a non-participant.
	 *
	 * @param string $threadId The thread.
	 * @param string $staffRef The staff member's user id.
	 * @param string $body     The message.
	 *
	 * @return bool Whether portaliq stored it.
	 *
	 * @spec openspec/changes/learner-list-and-page-follow-the-boards/tasks.md#T5
	 */
	public function post(string $threadId, string $staffRef, string $body): bool {
		$messaging = $this->messaging();
		if ($messaging === null) {
			return false;
		}

		try {
			return $messaging->postMessage($threadId, $staffRef, true, $body) === true;
		} catch (Throwable $exception) {
			$this->logger->warning('[PortaliqStaffMessages] the reply could not be posted: {msg}', ['msg' => $exception->getMessage()]);
			return false;
		}
	}//end post()

	/**
	 * Portaliq's messaging service, or null when portaliq is not installed.
	 *
	 * @return object|null
	 */
	private function messaging(): ?object {
		if ($this->appManager->isInstalled(self::APP_ID) === false) {
			return null;
		}

		try {
			$messaging = $this->container->get(self::MESSAGING_CLASS);
		} catch (Throwable $exception) {
			$this->logger->warning('[PortaliqStaffMessages] portaliq messaging is not resolvable: {msg}', ['msg' => $exception->getMessage()]);
			return null;
		}

		$methods = ['listThreads', 'listMessages', 'postMessage'];
		foreach ($methods as $method) {
			if (is_object($messaging) === false || method_exists($messaging, $method) === false) {
				return null;
			}
		}

		return $messaging;
	}//end messaging()
}//end class
