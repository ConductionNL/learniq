<?php

/**
 * Learniq Course Store Descriptor
 *
 * Learniq's parameters for OpenRegister's store plane (ADR-080): shared course
 * packages live as `shared-course-package` objects in the `learniq` register of
 * the configured registry. The registry URL, token and register come from
 * learniq's own app config (`registry_url`, `registry_token`,
 * `registry_register`), which the plane reads for this app id.
 *
 * Every card field maps to a string property on the registry object, because
 * the plane casts each mapped property to a string. `typeName` carries the one
 * line the shared store page shows under a card's title; `publisher` carries
 * the author, so the credit is on the card.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CourseStore
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
 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

use OCA\Learniq\AppInfo\Application;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;

/**
 * Builds the StoreDescriptor for shared course packages.
 */
class CourseStoreDescriptor {

	public const SCHEMA = 'shared-course-package';

	public const DEFAULT_REGISTER = 'learniq';

	public const KIND = 'course-package';

	/**
	 * Card field => registry object property.
	 */
	public const CARD_FIELDS = [
		'slug'        => 'slug',
		'title'       => 'title',
		'description' => 'description',
		'typeName'    => 'cardLine',
		'publisher'   => 'author',
		'version'     => 'version',
		'subject'     => 'subject',
		'level'       => 'level',
		'goals'       => 'goals',
		'language'    => 'language',
		'license'     => 'license',
		'author'      => 'author',
	];

	/**
	 * The descriptor the store plane searches and resolves with.
	 *
	 * @return StoreDescriptor
	 *
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane
	 */
	public function descriptor(): StoreDescriptor {
		return new StoreDescriptor(
			appId: Application::APP_ID,
			schema: self::SCHEMA,
			defaultRegister: self::DEFAULT_REGISTER,
			cardFields: self::CARD_FIELDS
		);

	}//end descriptor()

	/**
	 * Whether a slug names a shared course package
	 * (`course-package-<kebab>`, as CourseStoreRegistryObject::slug() builds it).
	 *
	 * @param string $slug The slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/lesson-sharing-via-store-plane/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
	 */
	public function isCourseSlug(string $slug): bool {
		return preg_match('/^course-package-[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug) === 1;

	}//end isCourseSlug()
}//end class
