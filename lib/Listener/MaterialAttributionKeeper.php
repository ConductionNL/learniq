<?php

/**
 * Learniq Material Attribution Keeper
 *
 * A Material added from an open source (Edurep, Edusources) carries its
 * attribution: source, record, address, collection, makers, licence, access
 * rights and the NC flag. Whatever edits the Material afterwards (its title,
 * its order, its lesson), those fields keep their stored values, so the makers
 * and the licence never drop off. A Material of the school's own is left alone.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Keeps a sourced Material's attribution through every edit.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */
class MaterialAttributionKeeper implements IEventListener {

	/**
	 * The schema this listener keeps.
	 */
	private const MATERIAL_SCHEMA = 'material';

	/**
	 * The source of a Material the school made itself.
	 */
	private const OWN_SOURCE = 'own';

	/**
	 * The attribution fields, which keep their stored values.
	 */
	public const ATTRIBUTION_FIELDS = ['source', 'sourceId', 'sourceUrl', 'sourceCollection', 'authors', 'license', 'accessRights', 'nonCommercial'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the event entity's schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Put the stored attribution back on an updated sourced Material.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = (new WrittenObject())->entityOf(event: $event);
		if ($this->isMaterial(entity: $entity) === false) {
			return;
		}

		$old = ($event->getOldObject()?->getObject() ?? []);
		if (self::isSourced(material: $old) === false) {
			return;
		}

		$entity->setObject(self::kept(old: $old, new: ($entity->getObject() ?? [])));
	}//end handle()

	/**
	 * The new Material with the old attribution.
	 *
	 * @param array<string, mixed> $old The stored Material.
	 * @param array<string, mixed> $new The Material as the edit would store it.
	 *
	 * @return array<string, mixed> The Material to store.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public static function kept(array $old, array $new): array {
		foreach (self::ATTRIBUTION_FIELDS as $field) {
			if (array_key_exists($field, $old) === true) {
				$new[$field] = $old[$field];
			}
		}

		return $new;
	}//end kept()

	/**
	 * Whether a Material came from an open source.
	 *
	 * @param array<string, mixed> $material The stored Material.
	 *
	 * @return bool True for a source other than the school's own.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public static function isSourced(array $material): bool {
		$source = (string)($material['source'] ?? self::OWN_SOURCE);

		return $source !== '' && $source !== self::OWN_SOURCE;
	}//end isSourced()

	/**
	 * Whether the entity is a Material.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isMaterial(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::MATERIAL_SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break another app's writes.
			return false;
		}
	}//end isMaterial()
}//end class
