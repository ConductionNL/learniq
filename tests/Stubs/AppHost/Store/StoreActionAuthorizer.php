<?php

/**
 * Test stub for OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer.
 *
 * OpenRegister's store authorizer: `can()` answers an install posture against
 * the declaring app's ADR-023 matrix, and `canPublish()` (openregister #4079,
 * store-plane-publish) answers whether a user is in one of the groups a
 * descriptor named. Learniq's CourseStorePublisher resolves it from the
 * container (store-publish-through-plane). Declaration-only: tests mock it.
 * Signatures match OpenRegister 84352bae.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\AppHost\Store
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Store;

use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCP\IUser;

/**
 * Stub for StoreActionAuthorizer.
 */
class StoreActionAuthorizer {

	/**
	 * Whether the user may perform the action the store declared.
	 *
	 * @param string $appId  The declaring leaf app id.
	 * @param string $action The dot-separated action name.
	 * @param IUser  $user   The signed-in user.
	 *
	 * @return bool
	 */
	public function can(string $appId, string $action, IUser $user): bool {
		return false;
	}//end can()

	/**
	 * Whether the user may publish through this descriptor.
	 *
	 * @param StoreDescriptor $descriptor The consuming app's store descriptor.
	 * @param IUser           $user       The signed-in user.
	 *
	 * @return bool
	 */
	public function canPublish(StoreDescriptor $descriptor, IUser $user): bool {
		return false;
	}//end canPublish()
}//end class
