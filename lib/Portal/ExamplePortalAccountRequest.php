<?php

/**
 * Learniq example portal account request
 *
 * One declared account asking for a portal account on a portal.
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
 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * A declared account read for its portal account: user id, audience and claims.
 *
 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
 */
final class ExamplePortalAccountRequest {

	/**
	 * The declared account as the set file has it.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $account;

	/**
	 * The portal the account is asked on.
	 *
	 * @var ExamplePortalTarget
	 */
	public readonly ExamplePortalTarget $portal;

	/**
	 * The trimmed Nextcloud user id, or ''.
	 *
	 * @var string
	 */
	public readonly string $uid;

	/**
	 * The trimmed audience, or ''.
	 *
	 * @var string
	 */
	public readonly string $audience;

	/**
	 * The declared claims, without empty values.
	 *
	 * @var array<string, string>
	 */
	public readonly array $claims;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $account The declared account.
	 * @param ExamplePortalTarget  $portal  The portal it is asked on.
	 *
	 * @return void
	 */
	public function __construct(array $account, ExamplePortalTarget $portal) {
		$this->account  = $account;
		$this->portal   = $portal;
		$this->uid      = trim((string)($account['userId'] ?? ''));
		$this->audience = trim((string)($account['portal']['audience'] ?? ''));
		$this->claims   = self::declaredClaims(account: $account);
	}//end __construct()

	/**
	 * Whether the account names both a user and an audience.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
	 */
	public function isNamed(): bool {
		return $this->uid !== '' && $this->audience !== '';
	}//end isNamed()

	/**
	 * The declared display name, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-portal-install-steps/specs/example-sets/spec.md#requirement-loading-a-set-gives-its-declared-learners-a-portal-account
	 */
	public function displayName(): string {
		return (string)($this->account['displayName'] ?? '');
	}//end displayName()

	/**
	 * The declared claims of an account, without empty values.
	 *
	 * @param array<string, mixed> $account The declared account.
	 *
	 * @return array<string, string>
	 */
	private static function declaredClaims(array $account): array {
		return array_filter(
			(array)($account['portal']['claims'] ?? []),
			static fn ($value): bool => is_string($value) === true && $value !== ''
		);
	}//end declaredClaims()
}//end class
