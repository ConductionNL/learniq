<?php

/**
 * Whether OpenRegister lets a user read one row, decided with its own matcher.
 *
 * Evaluates a shipped authorization block for one stored object with
 * OpenRegister's ConditionMatcher and OperatorEvaluator (copied verbatim into
 * tests/Stubs/Service), in the order PermissionHandler::evaluatePermission()
 * uses for a signed-in non-admin: the `authenticated` pseudo-group first, then
 * each of the user's groups, each through the read branch of
 * hasGroupPermission(): owner bypass, plain group entry, and a `{group, match}`
 * entry matched against the object with `$userId` resolved from the session.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Support
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Read verdicts over a shipped authorization block.
 */
trait OrReadVerdict {

	/**
	 * The shipped authorization block of a schema, by slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	protected static function shippedAuthorization(string $slug): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../lib/Settings/learniq_register.json'), true);
		$schemas  = array_column($register['components']['schemas'], null, 'slug');
		TestCase::assertArrayHasKey($slug, $schemas, 'the register ships no ' . $slug . ' schema');

		return $schemas[$slug]['authorization'];
	}//end shippedAuthorization()

	/**
	 * Whether OpenRegister lets this user read this row.
	 *
	 * @param array<string, mixed> $authorization The schema's authorization block.
	 * @param array<string, mixed> $object        The stored row.
	 * @param string               $userId        The signed-in user.
	 * @param array<int, string>   $groups        The user's Nextcloud groups.
	 * @param string|null          $owner         The row's owner (the creator).
	 *
	 * @return bool
	 */
	protected function canRead(array $authorization, array $object, string $userId, array $groups, ?string $owner): bool {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$matcher = new ConditionMatcher(
			userSession: $session,
			container: $this->createMock(ContainerInterface::class),
			operatorEvaluator: new OperatorEvaluator(logger: new NullLogger()),
			logger: new NullLogger()
		);

		foreach (array_merge(['authenticated'], $groups) as $groupId) {
			if ($groupId === 'admin' || ($owner !== null && $owner === $userId)) {
				return true;
			}

			foreach ($authorization['read'] ?? [] as $entry) {
				if (is_string($entry) === true && $entry === $groupId) {
					return true;
				}

				if (is_array($entry) === true && ($entry['group'] ?? null) === $groupId
					&& (empty($entry['match']) === true || $matcher->objectMatchesConditions(object: $object, match: $entry['match']) === true)
				) {
					return true;
				}
			}
		}

		return false;
	}//end canRead()
}//end trait
