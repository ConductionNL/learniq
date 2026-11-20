<?php

/**
 * Contract of CourseStorePublisher
 *
 * The public surface of CourseStorePublisher that other components call, so they depend
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

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCP\IUser;

/**
 * Contract of CourseStorePublisher.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CourseStorePublisherInterface {

	public const OUTCOME_OK = GenericStoreService::OUTCOME_OK;

	public const OUTCOME_NOT_CONFIGURED = GenericStoreService::OUTCOME_NOT_CONFIGURED;

	public const OUTCOME_UNREACHABLE = GenericStoreService::OUTCOME_UNREACHABLE;

	public const OUTCOME_INVALID = GenericStoreService::OUTCOME_INVALID;

	public const OUTCOME_REJECTED = 'store_rejected';

	public const OUTCOME_TOO_LARGE = 'too_large';

	public const OUTCOME_RATE_LIMITED = 'rate_limited';

	public const OUTCOME_NOT_PUBLISHABLE = 'not_publishable';

	/**
	 * Learniq's own refusal: the plane did not admit this user.
	 */
	public const OUTCOME_FORBIDDEN = 'forbidden';

	/**
	 * Learniq's own refusal: the installed OpenRegister has no publish path.
	 */
	public const OUTCOME_NOT_SUPPORTED = 'publish_not_supported';

	/**
	 * Whether a course registry is configured, as the plane reads it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
	 */
	public function isConfigured(): bool;

	/**
	 * Whether the plane admits this user as a publisher for the course store.
	 *
	 * The authorizer is resolved from the container here, not injected: an
	 * OpenRegister without the class would otherwise stop the DI container
	 * from building the store controller, taking search and install with it.
	 * Every failure to resolve or ask is a refusal, never a pass: an absent
	 * class, an object without canPublish(), or canPublish() throwing.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return bool True only when the plane answered yes.
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-plane-decides-who-may-publish-before-a-package-is-built
	 */
	public function mayPublish(IUser $user): bool;

	/**
	 * Publish a share package through the plane.
	 *
	 * @param array<string, mixed> $package The share package from the sharing gate.
	 *
	 * @return array{outcome: string, slug: string}
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
	 */
	public function publish(array $package): array;

	/**
	 * Whether the installed OpenRegister can publish: the descriptor opt-in
	 * exists and the plane has a publish() method.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-publishing-degrades-cleanly-on-an-openregister-without-the-write-path
	 */
	public function supportsPublish(): bool;
}//end interface
