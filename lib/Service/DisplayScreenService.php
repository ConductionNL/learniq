<?php

/**
 * Learniq Display Screen Service
 *
 * Issues and revokes the secret address of a hall screen, and answers the
 * public door behind it: the day's lessons of the screen's groups or rooms,
 * read through the timetable source (planninq when installed, learniq's own
 * sessions otherwise, D10), in a minimal shape with no learner, no user id,
 * no free-text reason and no affected people.
 *
 * The address is `<screen uuid>.<secret>`. Only the SHA-256 of the secret is
 * stored (`tokenHash`, `writeOnly` in the schema, so no read returns it), and
 * the public door compares hashes in constant time.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Security\ISecureRandom;
use Throwable;

/**
 * The secret address of a display screen.
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */
class DisplayScreenService {

	public const REGISTER = 'learniq';
	public const SCHEMA = 'display-screen';
	private const SECRET_LENGTH = 43;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister objects.
	 * @param ISecureRandom $random        Secret generator.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Create or renew the screen's address. The old address stops working.
	 *
	 * @param string $screenId The screen's uuid.
	 *
	 * @return string|null The new token, shown once, or null when the caller cannot see the screen.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
	 */
	public function issueToken(string $screenId): ?string {
		$screen = $this->loadAsCaller(screenId: $screenId);
		if ($screen === null) {
			return null;
		}

		$secret = $this->random->generate(self::SECRET_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$screen['tokenHash'] = hash('sha256', $secret);
		$screen['tokenCreatedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
		$screen['status'] = 'active';
		$this->objectService->saveObject(object: $screen, register: self::REGISTER, schema: self::SCHEMA);

		return $screenId.'.'.$secret;
	}//end issueToken()

	/**
	 * Revoke the screen's address at once.
	 *
	 * @param string $screenId The screen's uuid.
	 *
	 * @return bool False when the caller cannot see the screen.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
	 */
	public function revoke(string $screenId): bool {
		$screen = $this->loadAsCaller(screenId: $screenId);
		if ($screen === null) {
			return false;
		}

		$screen['tokenHash'] = null;
		$screen['status'] = 'revoked';
		$this->objectService->saveObject(object: $screen, register: self::REGISTER, schema: self::SCHEMA);

		return true;
	}//end revoke()

	/**
	 * The active screen a token opens, or null for an unknown, wrong or
	 * revoked token. Reads as the system: the caller is anonymous.
	 *
	 * @param string $token The address token.
	 *
	 * @return array<string, mixed>|null The screen.
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user
	 */
	public function screenForToken(string $token): ?array {
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			return null;
		}

		try {
			$row = $this->objectService->find(
				id: $parts[0],
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($row === null) {
			return null;
		}

		$screen = (array)($row->getObject() ?? []);
		$screen['id'] = $parts[0];
		if ($this->opens(screen: $screen, secret: $parts[1]) === false) {
			return null;
		}

		return $screen;
	}//end screenForToken()

	/**
	 * Whether a secret opens an active screen: constant-time hash comparison.
	 *
	 * @param array<string, mixed> $screen The raw screen row.
	 * @param string               $secret The secret half of the token.
	 *
	 * @return bool
	 */
	private function opens(array $screen, string $secret): bool {
		$hash = $screen['tokenHash'] ?? null;
		if (($screen['status'] ?? 'active') !== 'active' || is_string($hash) === false || $hash === '') {
			return false;
		}

		return hash_equals($hash, hash('sha256', $secret));
	}//end opens()

	/**
	 * Load a screen as the signed-in caller.
	 *
	 * @param string $screenId The screen's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadAsCaller(string $screenId): ?array {
		try {
			$row = $this->objectService->find(id: $screenId, register: self::REGISTER, schema: self::SCHEMA, _render: false);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($row === null) {
			return null;
		}

		$screen = (array)($row->getObject() ?? []);
		$screen['id'] = $screenId;

		return $screen;
	}//end loadAsCaller()
}//end class
