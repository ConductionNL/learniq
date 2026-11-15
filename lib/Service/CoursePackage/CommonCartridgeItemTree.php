<?php

/**
 * Learniq Common Cartridge item tree.
 *
 * @category Service
 * @package  OCA\Learniq\Service\CoursePackage
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
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\CoursePackage;

use DOMElement;

/**
 * Flattens the `<item>` elements of a manifest's organizations into rows, in
 * manifest order, with one running order counter across every organization walked.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
 */
final class CommonCartridgeItemTree {

	/**
	 * The order the next item gets.
	 *
	 * @var integer
	 */
	private int $order = 0;

	/**
	 * One row per walked item: `{identifier, title, order, parentIdentifier, isFolder, resourceIdentifier}`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $nodes = [];

	/**
	 * Walk the direct `<item>` children of an `<organization>` or `<item>`, depth first.
	 *
	 * @param DOMElement  $parent           The element whose direct `<item>` children are walked.
	 * @param string|null $parentIdentifier Identifier of the item this level nests under (null at the root).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	public function walk(DOMElement $parent, ?string $parentIdentifier): void {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === 'item') {
				$this->addItem(item: $child, parentIdentifier: $parentIdentifier);
			}
		}
	}//end walk()

	/**
	 * The rows walked so far.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-import-a-common-cartridge-or-moodle-course-package-into-the-courselessonmaterial-hierarchy
	 */
	public function nodes(): array {
		return $this->nodes;
	}//end nodes()

	/**
	 * Add one item's row, then walk its child items.
	 *
	 * @param DOMElement  $item             The `<item>` element.
	 * @param string|null $parentIdentifier Identifier of the item it nests under.
	 *
	 * @return void
	 */
	private function addItem(DOMElement $item, ?string $parentIdentifier): void {
		$row = $this->row(item: $item, parentIdentifier: $parentIdentifier);
		$this->nodes[] = $row;
		$this->order++;

		if ($row['isFolder'] === true) {
			$this->walk(parent: $item, parentIdentifier: $row['identifier']);
		}
	}//end addItem()

	/**
	 * One item's row, at the current order.
	 *
	 * @param DOMElement  $item             The `<item>` element.
	 * @param string|null $parentIdentifier Identifier of the item it nests under.
	 *
	 * @return array{identifier: string, title: string, order: int, parentIdentifier: string|null, isFolder: bool, resourceIdentifier: string|null}
	 */
	private function row(DOMElement $item, ?string $parentIdentifier): array {
		$identifier = $item->getAttribute('identifier');
		return [
			'identifier'         => $identifier,
			'title'              => self::firstChildText(element: $item, tagName: 'title') ?? $identifier,
			'order'              => $this->order,
			'parentIdentifier'   => $parentIdentifier,
			'isFolder'           => self::hasChildItem(element: $item),
			'resourceIdentifier' => self::nonEmptyOrNull(value: $item->getAttribute('identifierref')),
		];
	}//end row()

	/**
	 * Whether the element has a direct `<item>` child.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return bool
	 */
	private static function hasChildItem(DOMElement $element): bool {
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === 'item') {
				return true;
			}
		}

		return false;
	}//end hasChildItem()

	/**
	 * The trimmed text of the first direct child with the given local name.
	 *
	 * @param DOMElement $element The parent element.
	 * @param string     $tagName The child's local name (namespace-agnostic).
	 *
	 * @return string|null The text, or null when there is no such child or its text is empty.
	 */
	private static function firstChildText(DOMElement $element, string $tagName): ?string {
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === $tagName) {
				return self::nonEmptyOrNull(value: trim((string)$child->textContent));
			}
		}

		return null;
	}//end firstChildText()

	/**
	 * The value, or null when it is the empty string.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private static function nonEmptyOrNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nonEmptyOrNull()
}//end class
