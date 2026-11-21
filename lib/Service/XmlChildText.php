<?php

/**
 * Learniq text of an XML element's first child with a given tag.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec exclude XML plumbing, not a behavioural requirement: the child-text
 *       read the Moodle backup readers share.
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DOMElement;

/**
 * Reads the trimmed text of an element's first direct child with a tag.
 *
 * @spec exclude XML plumbing, not a behavioural requirement.
 */
final class XmlChildText {

	/**
	 * The trimmed text of the first direct child with the tag; null when that
	 * child is blank or there is none.
	 *
	 * @param DOMElement $element The parent element.
	 * @param string $tagName The child's tag name.
	 *
	 * @return string|null
	 *
	 * @spec exclude XML plumbing, not a behavioural requirement.
	 */
	public function firstOf(DOMElement $element, string $tagName): ?string {
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement && $child->tagName === $tagName) {
				$text = trim((string)$child->textContent);
				if ($text !== '') {
					return $text;
				}

				return null;
			}
		}

		return null;
	}//end firstOf()
}//end class
