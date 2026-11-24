<?php

/**
 * Unit tests for MaterialAttributionKeeper.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\MaterialAttributionKeeper;
use OCA\Learniq\Listener\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Editing a sourced Material's title keeps its attribution; the school's own material is left alone.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */
class MaterialAttributionKeeperTest extends TestCase {

	/**
	 * A Material added from Edurep, as stored.
	 */
	private const SOURCED = [
		'id'               => 'mat-1',
		'title'            => 'Het schrijven van een betoog',
		'kind'             => 'link',
		'url'              => 'https://maken.wikiwijs.nl/194413',
		'source'           => 'edurep',
		'sourceId'         => 'urn:wikiwijsmaken:194413',
		'sourceUrl'        => 'https://maken.wikiwijs.nl/194413',
		'sourceCollection' => 'wikiwijsmaken',
		'authors'          => ['Kirstin Beekman'],
		'license'          => 'CC-BY-4.0',
		'accessRights'     => 'OpenAccess',
		'nonCommercial'    => false,
	];

	/**
	 * @param string $slug           The schema the resolver reports.
	 * @param bool   $resolverThrows Whether the resolver cannot tell the schema.
	 *
	 * @return MaterialAttributionKeeper
	 */
	private function keeper(string $slug = 'material', bool $resolverThrows = false): MaterialAttributionKeeper {
		$resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		if ($resolverThrows === true) {
			$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('unknown schema'));
		} else {
			$resolver->method('guardSchemaSlug')->willReturn($slug);
		}

		return new MaterialAttributionKeeper(schemaResolver: $resolver);
	}//end keeper()

	/**
	 * @param array<string, mixed> $new    The Material as the edit sends it.
	 * @param array<string, mixed> $stored The stored Material.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(array $new, array $stored = self::SOURCED): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(OrEntityFactory::make($new, 'material'), OrEntityFactory::make($stored, 'material'));
	}//end update()

	/**
	 * The spec's scenario: a teacher renames a linked material and the makers and licence stay.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public function testEditingTheTitleKeepsAttribution(): void {
		$edit  = ['id' => 'mat-1', 'title' => 'Betoog schrijven (les 3)', 'kind' => 'link', 'url' => self::SOURCED['url']];
		$event = $this->update(new: $edit);

		$this->keeper()->handle($event);

		$stored = $event->getNewObject()->getObject();
		self::assertSame(expected: 'Betoog schrijven (les 3)', actual: $stored['title']);
		foreach (MaterialAttributionKeeper::ATTRIBUTION_FIELDS as $field) {
			self::assertSame(expected: self::SOURCED[$field], actual: $stored[$field], message: $field);
		}
	}//end testEditingTheTitleKeepsAttribution()

	/**
	 * An edit that changes the makers or the licence keeps the stored ones.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public function testAnEditCannotChangeTheMakersOrTheLicence(): void {
		$event = $this->update(new: array_merge(self::SOURCED, ['authors' => [], 'license' => 'CC0-1.0', 'nonCommercial' => false]));

		$this->keeper()->handle($event);

		$stored = $event->getNewObject()->getObject();
		self::assertSame(expected: [['Kirstin Beekman'], 'CC-BY-4.0'], actual: [$stored['authors'], $stored['license']]);
	}//end testAnEditCannotChangeTheMakersOrTheLicence()

	/**
	 * The school's own material, another schema, a create and an unknown schema pass untouched.
	 *
	 * @return void
	 */
	public function testOwnMaterialOtherSchemasAndCreatesAreLeftAlone(): void {
		$own   = ['id' => 'mat-2', 'title' => 'Eigen dia\'s', 'kind' => 'slides', 'source' => 'own', 'license' => 'other'];
		$event = $this->update(new: array_merge($own, ['license' => 'CC-BY-4.0']), stored: $own);
		$this->keeper()->handle($event);
		self::assertSame(expected: 'CC-BY-4.0', actual: $event->getNewObject()->getObject()['license']);

		foreach ([$this->keeper(slug: 'lesson'), $this->keeper(resolverThrows: true)] as $keeper) {
			$event = $this->update(new: ['id' => 'mat-1', 'title' => 'x', 'authors' => []]);
			$keeper->handle($event);
			self::assertSame(expected: [], actual: $event->getNewObject()->getObject()['authors']);
		}

		$created = OrEntityFactory::make(['title' => 'x', 'source' => 'edurep', 'authors' => []], 'material');
		$this->keeper()->handle(new ObjectCreatingEvent($created));
		self::assertSame(expected: ['title' => 'x', 'source' => 'edurep', 'authors' => []], actual: array_intersect_key($created->getObject(), ['title' => 1, 'source' => 1, 'authors' => 1]));
	}//end testOwnMaterialOtherSchemasAndCreatesAreLeftAlone()

	/**
	 * A Material without a source counts as the school's own.
	 *
	 * @return void
	 */
	public function testAMaterialWithoutASourceIsOwn(): void {
		self::assertFalse(condition: MaterialAttributionKeeper::isSourced(material: ['title' => 'x']));
		self::assertFalse(condition: MaterialAttributionKeeper::isSourced(material: ['source' => '']));
		self::assertTrue(condition: MaterialAttributionKeeper::isSourced(material: ['source' => 'edusources']));
	}//end testAMaterialWithoutASourceIsOwn()

	/**
	 * The keeper runs on every Material update.
	 *
	 * @return void
	 */
	public function testTheKeeperIsRegistered(): void {
		$pairs   = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event.' => '.$listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(needle: ObjectUpdatingEvent::class.' => '.MaterialAttributionKeeper::class, haystack: $pairs);
	}//end testTheKeeperIsRegistered()
}//end class
