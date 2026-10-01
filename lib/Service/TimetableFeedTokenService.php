<?php

/**
 * Learniq Timetable Feed Token Service
 *
 * One revocable calendar feed token per user. The token is 32 random bytes
 * (64 hex characters), handed to the user once, and only its SHA-256 hash is
 * stored, as an indexed user preference. A new token replaces the old one, so
 * the old feed address stops working at once (attendance-timetable-calendar-feed).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\Security\ISecureRandom;

/**
 * Creates, resolves and revokes a user's calendar feed token.
 */
class TimetableFeedTokenService {
	/**
	 * User preference key holding the token hash.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'timetable_feed_token_hash';

	/**
	 * Characters of a token: 64 lowercase hex characters, 32 random bytes.
	 *
	 * @var string
	 */
	public const TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

	/**
	 * Constructor.
	 *
	 * @param IUserConfig   $userConfig User preferences.
	 * @param ISecureRandom $random     Secure random source.
	 */
	public function __construct(
		private readonly IUserConfig $userConfig,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Create a new token for the user, replacing any earlier one.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string The token, shown to the user once.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
	 */
	public function issue(string $uid): string {
		$token = $this->random->generate(64, '0123456789abcdef');
		$this->userConfig->setValueString(
			$uid,
			Application::APP_ID,
			self::CONFIG_KEY,
			$this->hash(token: $token),
			false,
			IUserConfig::FLAG_INDEXED
		);

		return $token;
	}//end issue()

	/**
	 * Whether the user has a feed address.
	 *
	 * @param string $uid The user id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-calendar-subscription-feed
	 */
	public function exists(string $uid): bool {
		return $this->userConfig->getValueString($uid, Application::APP_ID, self::CONFIG_KEY) !== '';
	}//end exists()

	/**
	 * Remove the user's token, so their feed address answers 404.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
	 */
	public function revoke(string $uid): void {
		$this->userConfig->deleteUserConfig($uid, Application::APP_ID, self::CONFIG_KEY);
	}//end revoke()

	/**
	 * The user a token belongs to, or null.
	 *
	 * A malformed token, an unknown or replaced token, and a hash that more
	 * than one user holds all answer null.
	 *
	 * @param string $token The token from the feed address.
	 *
	 * @return string|null The user id.
	 *
	 * @spec openspec/changes/attendance-timetable-calendar-feed/specs/timetable-calendar-feed/spec.md#requirement-revoking-the-feed-address
	 */
	public function userFor(string $token): ?string {
		if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
			return null;
		}

		$hash = $this->hash(token: $token);
		$users = [];
		foreach ($this->userConfig->searchUsersByValueString(Application::APP_ID, self::CONFIG_KEY, $hash) as $uid) {
			$users[] = (string)$uid;
		}

		if (count($users) !== 1) {
			return null;
		}

		// The lookup matched on the stored value; compare again in constant
		// time against the user's own value before trusting it.
		$stored = $this->userConfig->getValueString($users[0], Application::APP_ID, self::CONFIG_KEY);
		if ($stored === '' || hash_equals($stored, $hash) === false) {
			return null;
		}

		return $users[0];
	}//end userFor()

	/**
	 * The stored form of a token.
	 *
	 * @param string $token The token.
	 *
	 * @return string The SHA-256 hex digest.
	 */
	private function hash(string $token): string {
		return hash('sha256', $token);
	}//end hash()
}//end class
