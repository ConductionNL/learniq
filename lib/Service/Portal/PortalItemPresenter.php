<?php

/**
 * Learniq Portal Item Presenter
 *
 * Turns an Item's QTI body into the shape portaliq's timed task renders:
 * `{itemId, type, prompt, points, choices?, sources?, targets?}`. Pure.
 *
 * It reads the item body only (the prompt, the body's own text, the options)
 * and never the response declaration, so no correct answer can leave learniq.
 * A body that is not XML yields the item's title as its prompt. Options follow
 * the attempt's frozen option order. Both QTI 2.x (`simpleChoice`) and QTI 3.0
 * (`qti-simple-choice`) element names are read, by local name, so a namespace
 * or prefix makes no difference.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * QTI to portal item payload.
 *
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalItemPresenter {

	/**
	 * The longest prompt and option label sent.
	 */
	private const MAX_PROMPT_LENGTH = 2000;

	/**
	 * Option element names per interaction type (QTI 2.x and 3.0).
	 */
	private const OPTION_ELEMENTS = [
		'choice' => ['simpleChoice', 'qti-simple-choice'],
		'order' => ['simpleChoice', 'qti-simple-choice'],
		'inlineChoice' => ['inlineChoice', 'qti-inline-choice'],
	];

	/**
	 * The item body element names.
	 */
	private const BODY_ELEMENTS = ['itemBody', 'qti-item-body'];

	/**
	 * The prompt element names.
	 */
	private const PROMPT_ELEMENTS = ['prompt', 'qti-prompt'];

	/**
	 * Present one drawn item.
	 *
	 * @param array<string, mixed> $item The Item row.
	 * @param array<string, mixed> $drawnRef The attempt's drawnItemRefs entry for it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function present(array $item, array $drawnRef): array {
		$type = (string)($item['interactionType'] ?? 'textEntry');
		$xpath = $this->parse(qtiBody: $item['qtiBody'] ?? '');

		$presented = [
			'itemId' => (string)($drawnRef['itemId'] ?? ($item['id'] ?? '')),
			'type' => $type,
			'prompt' => $this->prompt(xpath: $xpath, title: (string)($item['title'] ?? '')),
			'points' => ($drawnRef['points'] ?? ($item['maxScore'] ?? 0)),
		];

		if ($xpath === null) {
			return $presented;
		}

		if (isset(self::OPTION_ELEMENTS[$type]) === true) {
			$presented['choices'] = $this->ordered(
				options: $this->options(xpath: $xpath, names: self::OPTION_ELEMENTS[$type], context: null),
				order: ($drawnRef['optionOrder'] ?? null)
			);
		}

		if ($type === 'match') {
			$sets = $this->elements(xpath: $xpath, names: ['simpleMatchSet', 'qti-simple-match-set'], context: null);
			$names = ['simpleAssociableChoice', 'qti-simple-associable-choice'];
			$presented['sources'] = $this->options(xpath: $xpath, names: $names, context: ($sets[0] ?? null));
			$presented['targets'] = $this->options(xpath: $xpath, names: $names, context: ($sets[1] ?? null));
		}

		return $presented;
	}//end present()

	/**
	 * Parse a QTI body, with network access off and errors suppressed.
	 *
	 * @param mixed $qtiBody The stored body.
	 *
	 * @return DOMXPath|null Null when the body is not XML.
	 */
	private function parse(mixed $qtiBody): ?DOMXPath {
		if (is_string($qtiBody) === false || str_starts_with(ltrim($qtiBody), '<') === false) {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$loaded = $doc->loadXML($qtiBody, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($loaded === false) {
			return null;
		}

		return new DOMXPath($doc);
	}//end parse()

	/**
	 * The prompt: a prompt element, else the item body's own text (without its
	 * options), else the title. Never text from outside the item body.
	 *
	 * @param DOMXPath|null $xpath The parsed body.
	 * @param string $title The item's title.
	 *
	 * @return string
	 */
	private function prompt(?DOMXPath $xpath, string $title): string {
		if ($xpath === null) {
			return $title;
		}

		$prompt = ($this->elements(xpath: $xpath, names: self::PROMPT_ELEMENTS, context: null)[0] ?? null);
		if ($prompt !== null) {
			$text = $this->clean(text: (string)$prompt->textContent);
			if ($text !== '') {
				return $text;
			}
		}

		$body = ($this->elements(xpath: $xpath, names: self::BODY_ELEMENTS, context: null)[0] ?? null);
		if ($body !== null) {
			$text = $this->clean(text: $this->ownText(node: $body));
			if ($text !== '') {
				return $text;
			}
		}

		return $title;
	}//end prompt()

	/**
	 * The text of a node, skipping interactions and their options.
	 *
	 * @param DOMNode $node The node.
	 *
	 * @return string
	 */
	private function ownText(DOMNode $node): string {
		$text = '';
		foreach ($node->childNodes as $child) {
			if ($child instanceof DOMElement === true && $this->isInteraction(name: $child->localName ?? '') === true) {
				continue;
			}

			if ($child instanceof DOMElement === true) {
				$text .= ' ' . $this->ownText(node: $child);
				continue;
			}

			$text .= ' ' . (string)$child->textContent;
		}

		return $text;
	}//end ownText()

	/**
	 * Whether an element is an interaction (it holds the options).
	 *
	 * @param string $name The element's local name.
	 *
	 * @return bool
	 */
	private function isInteraction(string $name): bool {
		return str_ends_with($name, 'Interaction') === true || str_ends_with($name, '-interaction') === true;
	}//end isInteraction()

	/**
	 * Option elements as `{id, label}`, in document order.
	 *
	 * @param DOMXPath $xpath The parsed body.
	 * @param array<int, string> $names Element local names.
	 * @param DOMNode|null $context Search below this node, or the whole body.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	private function options(DOMXPath $xpath, array $names, ?DOMNode $context): array {
		$options = [];
		foreach ($this->elements(xpath: $xpath, names: $names, context: $context) as $element) {
			$id = $element->getAttribute('identifier');
			if ($id === '') {
				continue;
			}

			$options[] = ['id' => $id, 'label' => $this->clean(text: (string)$element->textContent)];
		}

		return $options;
	}//end options()

	/**
	 * Elements with one of the local names, below a context or anywhere.
	 *
	 * @param DOMXPath $xpath The parsed body.
	 * @param array<int, string> $names Element local names.
	 * @param DOMNode|null $context The context node.
	 *
	 * @return array<int, DOMElement>
	 */
	private function elements(DOMXPath $xpath, array $names, ?DOMNode $context): array {
		$tests = array_map(static fn (string $name): string => "local-name()='" . $name . "'", $names);
		$prefix = '//';
		if ($context !== null) {
			$prefix = './/';
		}

		$nodes = $xpath->query($prefix . '*[' . implode(' or ', $tests) . ']', $context);
		if ($nodes === false) {
			return [];
		}

		$elements = [];
		foreach ($nodes as $node) {
			if ($node instanceof DOMElement === true) {
				$elements[] = $node;
			}
		}

		return $elements;
	}//end elements()

	/**
	 * Options in the attempt's frozen order; unlisted ones follow in body order.
	 *
	 * @param array<int, array{id: string, label: string}> $options The options.
	 * @param mixed $order The drawn optionOrder, or null.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	private function ordered(array $options, mixed $order): array {
		if (is_array($order) === false || $order === []) {
			return $options;
		}

		$byId = [];
		foreach ($options as $option) {
			$byId[$option['id']] = $option;
		}

		$result = [];
		foreach ($order as $id) {
			if (is_string($id) === true && isset($byId[$id]) === true) {
				$result[] = $byId[$id];
				unset($byId[$id]);
			}
		}

		return array_merge($result, array_values($byId));
	}//end ordered()

	/**
	 * Collapse whitespace and cap the length.
	 *
	 * @param string $text The raw text.
	 *
	 * @return string
	 */
	private function clean(string $text): string {
		return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $text)), 0, self::MAX_PROMPT_LENGTH);
	}//end clean()
}//end class
