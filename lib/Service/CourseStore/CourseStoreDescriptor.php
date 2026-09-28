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
 * Publishing (store-publish-through-plane): the descriptor names what may leave
 * this server (PUBLISH_FIELDS) and who may send it (the groups learniq's own
 * ADR-023 matrix holds for `course-package.share`), which is how OpenRegister's
 * GenericStoreService::publish() lets a descriptor opt in. Both are passed only
 * when the installed OpenRegister knows them: a named argument the constructor
 * does not declare is a fatal error, not a false.
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
 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane
 * @spec openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CourseStore;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
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
	 * The ADR-023 action whose groups may publish.
	 */
	public const ACTION_PUBLISH = 'course-package.share';

	/**
	 * Registry object properties a publish may send next to the slug: exactly
	 * the keys CourseStoreRegistryObject::build() writes after the sharing
	 * gate stripped the package, and nothing else.
	 */
	public const PUBLISH_FIELDS = [
		'kind',
		'title',
		'description',
		'subject',
		'level',
		'levels',
		'goals',
		'goalsCovered',
		'language',
		'license',
		'author',
		'cardLine',
		'version',
		'lessonCount',
		'sharedAt',
		'package',
	];

	/**
	 * Constructor.
	 *
	 * @param ActionAuthService $actionAuth Learniq's ADR-023 matrix, the source of the publish groups.
	 */
	public function __construct(
		private readonly ActionAuthService $actionAuth,
	) {

	}//end __construct()

	/**
	 * The descriptor the store plane searches and resolves with.
	 *
	 * @return StoreDescriptor
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-lists-shared-courses-through-the-store-plane
	 * @spec openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
	 */
	public function descriptor(): StoreDescriptor {
		$arguments = [
			'appId'           => Application::APP_ID,
			'schema'          => self::SCHEMA,
			'defaultRegister' => self::DEFAULT_REGISTER,
			'cardFields'      => self::CARD_FIELDS,
		];

		if ($this->supportsPublish() === true) {
			$arguments['publishFields'] = self::PUBLISH_FIELDS;
			$arguments['publishGroups'] = array_values($this->actionAuth->getAllowedGroups(action: self::ACTION_PUBLISH));
		}

		return new StoreDescriptor(...$arguments);

	}//end descriptor()

	/**
	 * Whether the installed OpenRegister's StoreDescriptor carries the publish
	 * opt-in (openregister #4079). Promoted constructor properties are declared
	 * properties, so property_exists() sees them on the class.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/store-publish-through-plane/specs/course-management/spec.md#requirement-publishing-degrades-cleanly-on-an-openregister-without-the-write-path
	 */
	public function supportsPublish(): bool {
		return property_exists(StoreDescriptor::class, 'publishFields') === true
			&& property_exists(StoreDescriptor::class, 'publishGroups') === true
			&& $this->descriptorHas(method: 'isPublishable') === true;

	}//end supportsPublish()

	/**
	 * Whether the installed OpenRegister's StoreDescriptor declares a method.
	 *
	 * A runtime question on purpose: static analysis reads the declaration
	 * stub of the newest OpenRegister, while an instance may run an older one.
	 *
	 * @param string $method The method name.
	 *
	 * @return bool
	 */
	private function descriptorHas(string $method): bool {
		return method_exists(StoreDescriptor::class, $method);

	}//end descriptorHas()

	/**
	 * Whether a slug names a shared course package
	 * (`course-package-<kebab>`, as CourseStoreRegistryObject::slug() builds it).
	 *
	 * @param string $slug The slug.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-installing-a-shared-course-creates-an-independent-copy-that-keeps-the-credit
	 */
	public function isCourseSlug(string $slug): bool {
		return preg_match('/^course-package-[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug) === 1;

	}//end isCourseSlug()
}//end class
