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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IUser;

/**
 * Mirror of OpenRegister's ObjectService for standalone Learniq unit tests.
 */
abstract class ObjectService {

	/**
	 * Find a single object.
	 *
	 * @param int|string             $id            Object identifier.
	 * @param ObjectFindOptions|null $options       Register and schema scope, extend, files, render and audit.
	 * @param bool                   $_rbac         Apply RBAC.
	 * @param bool                   $_multitenancy Apply multitenancy.
	 *
	 * @return ObjectEntity|null
	 */
	abstract public function find(
		int|string $id,
		?ObjectFindOptions $options = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
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
	 * @param bool $_validation Validate against the schema.
	 * @param array<string,mixed>|null $uploadedFiles Uploaded files.
	 * @param IUser|null $currentUser Acting user.
	 * @param bool $failIfExists Fail on conflict.
	 * @param bool $_unowned     Stamp the system identity as owner even with a session (OR 3de558cc7d).
	 * @param bool $_dedupOverride Save over a duplicate the dedup check would refuse.
	 * @param string|null $_owner Explicit owner.
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
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
		bool $_unowned = false,
		bool $_dedupOverride = false,
		?string $_owner = null,
	): ObjectEntity;

	/**
	 * Delete an object.
	 *
	 * @param string                   $uuid          Object uuid.
	 * @param Register|string|int|null $register      Register context.
	 * @param Schema|string|int|null   $schema        Schema context.
	 * @param bool                     $_rbac         Apply RBAC.
	 * @param bool                     $_multitenancy Apply multitenancy.
	 * @param bool                     $_retentionSweep Internal retention-sweep flag.
	 * @param IUser|null               $currentUser   Explicit acting user.
	 * @param bool                     $permanent     Destroy the row rather than tombstone it.
	 *
	 * @return bool
	 */
	abstract public function deleteObject(
		string $uuid,
		Register|string|int|null $register = null,
		Schema|string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_retentionSweep = false,
		?IUser $currentUser = null,
		bool $permanent = false,
	): bool;

	/**
	 * Run a callable as a named user, with that user's RBAC and multitenancy.
	 *
	 * Mirrors the real signature exactly: no return type.
	 *
	 * @param IUser    $user      The user to act as.
	 * @param callable $operation The operation to execute as that user.
	 *
	 * @return mixed
	 */
	abstract public function runAs(IUser $user, callable $operation);

	/**
	 * Run a trusted callable with system rights (no RBAC, no multitenancy).
	 *
	 * Mirrors the real signature exactly: no return type.
	 *
	 * @param callable $operation The trusted operation to execute.
	 *
	 * @return mixed
	 */
	abstract public function runAsSystem(callable $operation);

	/**
	 * Run a callable acting for an organisation, with no user: the
	 * organisation filter stays on and creates get that organisation.
	 *
	 * Mirrors the real signature exactly: no return type.
	 *
	 * @param string   $organisationUuid The organisation to act for.
	 * @param callable $operation        The operation to run.
	 *
	 * @return mixed
	 */
	abstract public function runForOrganisation(string $organisationUuid, callable $operation);

}//end class
