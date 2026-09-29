<?php

/**
 * Learniq Agent Tool Answer
 *
 * Shapes what learniq's agent tools answer: `{ok: true, ...}` on success and
 * `{ok: false, error: {code, message}}` on a refusal, so an agent can tell a
 * refusal from a result without parsing prose. Also resolves display names
 * for the minimised credential read.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-declared-scope-req-007
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IUserManager;

/**
 * Answer envelopes for the agent tools.
 */
class AgentToolAnswer {

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Resolves display names.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * A success envelope.
	 *
	 * @param array<string, mixed> $data The result.
	 *
	 * @return array<string, mixed> `{ok: true, ...data}`.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-declared-scope-req-007
	 */
	public function success(array $data): array {
		return array_merge(['ok' => true], $data);
	}//end success()

	/**
	 * A refusal envelope.
	 *
	 * @param string $code    A short machine code.
	 * @param string $message What to tell the user.
	 *
	 * @return array<string, mixed> `{ok: false, error: {code, message}}`.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-write-actions-are-curated-tools-with-declared-scope-req-007
	 */
	public function error(string $code, string $message): array {
		return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
	}//end error()

	/**
	 * A user's display name, or the uid when the user is unknown.
	 *
	 * @param string $uid The uid.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-the-expiring-credentials-read-is-a-closed-minimised-projection-req-011
	 */
	public function displayName(string $uid): string {
		if ($uid === '') {
			return '';
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			return $uid;
		}

		return $user->getDisplayName();
	}//end displayName()
}//end class
