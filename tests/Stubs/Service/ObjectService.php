<?php

/**
 * Test stub for OCA\OpenRegister\Service\ObjectService.
 *
 * THIS STUB IS A MIRROR, NOT A CONVENIENCE. It must stay signature-for-signature
 * faithful to the real OpenRegister class, because the two swap places depending
 * on where the suite runs: standalone the `OCA\OpenRegister\ => tests/Stubs/`
 * PSR-4 mapping resolves the name here, while in CI the app sits in a real
 * Nextcloud server tree next to openregister@development and Nextcloud's
 * autoloader resolves it to the REAL class.
 *
 * The previous version of this file declared an invented three-argument
 * `saveObject(string $register, string $schema, array $object)` and a
 * three-argument `find(string $id, string $register, string $schema)`, and its
 * own docblock asserted that OpenRegister "accepts two call signatures". It
 * does not, and never did. Because every mock in the suite was built from that
 * fiction, 887 tests passed standalone and 231 of them errored the moment the
 * real class was on the autoloader. `OpenRegisterContractTest` now asserts the
 * mirror against whichever class actually resolves, so the next drift is a
 * single red test instead of a silently dead suite.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * Mirror of OpenRegister's ObjectService for standalone Learniq unit tests.
 */
abstract class ObjectService {

	/**
	 * Find a single object.
	 *
	 * @param int|string $id Object identifier.
	 * @param array<string,mixed>|null $_extend Extend directives.
	 * @param bool $files Include files.
	 * @param Register|string|int|null $register Register context.
	 * @param Schema|string|int|null $schema Schema context.
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 * @param bool $_render Render the result.
	 *
	 * @return ObjectEntity|null
	 */
	abstract public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true,
	): ?ObjectEntity;

	/**
	 * Find all objects matching a config.
	 *
	 * @param array<string,mixed> $config Query config.
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 *
	 * @return array<int,mixed>
	 */
	abstract public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Persist an object.
	 *
	 * @param array<string,mixed>|ObjectEntity $object Object payload.
	 * @param array<string,mixed>|null $extend Extend directives.
	 * @param Register|string|int|null $register Register context.
	 * @param Schema|string|int|null $schema Schema context.
	 * @param string|null $uuid Explicit uuid.
	 * @param bool $_rbac Apply RBAC.
	 * @param bool $_multitenancy Apply multitenancy.
	 * @param bool $silent Suppress events.
	 * @param array<string,mixed>|null $uploadedFiles Uploaded files.
	 * @param IUser|null $currentUser Acting user.
	 * @param bool $failIfExists Fail on conflict.
	 *
	 * @return ObjectEntity
	 */
	abstract public function saveObject(
		array|ObjectEntity $object,
		?array $extend = [],
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
	): ObjectEntity;

}//end class
