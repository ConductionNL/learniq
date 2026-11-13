<?php

/**
 * Contract of Cmi5LaunchTokenService
 *
 * The public surface of Cmi5LaunchTokenService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of Cmi5LaunchTokenService.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface Cmi5LaunchTokenServiceInterface {

	/**
	 * Wrap a launch JWT into the cmi5 `auth-token` the AU sends as `Authorization: Basic <auth-token>`.
	 *
	 * The cmi5 spec (§8.2.3) makes the AU send the auth-token verbatim as Basic credentials.
	 * Nextcloud base64-decodes every Basic header and, when the result has the
	 * `user:password` shape, attempts a login and answers 401 before any app
	 * code runs (`OC\User\Session::tryBasicAuthLogin`, caught in `index.php`).
	 * A bare JWT has that shape: its header decodes to `{"alg":"RS256",...`.
	 * The base64 of the JWT decodes to the JWT itself, whose alphabet
	 * (base64url plus `.`) never contains a colon, so Nextcloud sees no
	 * credentials, skips the login and lets the public LRS route run.
	 *
	 * @param string $launchToken The compact JWT from mintLaunchToken().
	 *
	 * @return string The auth-token handed out by the fetch URL.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#7-basic-auth-reachability
	 */
	public function authToken(string $launchToken): string;

	/**
	 * Generate a fresh RSA-2048 key-pair and store it, replacing any old one.
	 *
	 * @return array{fingerprint: string, publicKey: string} The new public key and its SHA-256 fingerprint.
	 *
	 * @throws RuntimeException When openssl cannot generate or export the key.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#1-key-provisioning
	 */
	public function generateKeyPair(): array;

	/**
	 * Whether cmi5 launch token minting is available.
	 *
	 * Controllers MUST call this before mintLaunchToken() and answer HTTP 503
	 * with a human-readable body when false.
	 *
	 * @return bool True once both halves of the key-pair are stored.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#2-cmi5launchtokenservice
	 */
	public function isEnabled(): bool;

	/**
	 * The stored public key and its fingerprint, or null when none is stored.
	 *
	 * @return array{fingerprint: string, publicKey: string}|null The key status.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#1-key-provisioning
	 */
	public function keyStatus(): ?array;

	/**
	 * Mint a cmi5 AU launch JWT (RS256).
	 *
	 * Always call isEnabled() first: without a stored key this throws.
	 *
	 * @param string $learnerId      The learner's Nextcloud user id.
	 * @param string $lessonId       The lesson UUID (the AU).
	 * @param string $registrationId A UUID identifying this launch attempt.
	 * @param string $activityId     The AU's xAPI activity IRI.
	 *
	 * @return string The signed compact JWT.
	 *
	 * @throws RuntimeException When no key is stored or signing fails.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#2-cmi5launchtokenservice
	 */
	public function mintLaunchToken(
		string $learnerId,
		string $lessonId,
		string $registrationId,
		string $activityId,
	): string;
}//end interface
