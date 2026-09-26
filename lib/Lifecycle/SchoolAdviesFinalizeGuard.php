<?php

/**
 * Learniq School Advies Finalize Guard
 *
 * Lifecycle guard for the SchoolAdvies schema's `vaststellenDefinitief`
 * transition (`voorlopig -> definitief`). Blocks finalisation when
 * `doorstroomtoetsResultLevel` outranks `definitiefAdviesLevel` on the shared
 * low-to-high ordinal — unless `heroverwegingMotivation` is non-empty, or
 * both levels are `pro`/`vmbo-bb` (po-schooladvies-flow change).
 *
 * Mirrors `AdmissionsDecisionGuard`'s VO-side `schooladviesAdjustmentSatisfied()`
 * branch exactly (same ordinal, same exemption), applied to `SchoolAdvies`'s
 * own fields rather than `Application`'s. Kept as a separate, single-schema
 * guard rather than generalising `AdmissionsDecisionGuard`: the two schemas
 * have different transition names, different lifecycles, and no
 * admissions-round/capacity dimension on the PO side (see design.md
 * Trade-offs).
 *
 * Legitimate PHP per ADR-031 "domain rule requiring cross-field conditional
 * logic" — the same exception `AdmissionsDecisionGuard` already established
 * for the identical rule shape; this register's calculation DSL has no
 * `requires`-compatible boolean-short-circuit primitive for "block unless A
 * OR B OR C".
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-a-po-schooladvies-may-only-be-raised-on-heroverweging-never-lowered-unless-motivated
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Guards the SchoolAdvies `vaststellenDefinitief` (voorlopig -> definitief) transition.
 *
 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-a-po-schooladvies-may-only-be-raised-on-heroverweging-never-lowered-unless-motivated
 */
class SchoolAdviesFinalizeGuard {

	/**
	 * The shared low->high schooladvies/doorstroomtoets ordinal, identical to
	 * `AdmissionsDecisionGuard::ORDINAL_LEVELS`.
	 *
	 * @var string[]
	 */
	private const ORDINAL_LEVELS = [
		'pro',
		'vmbo-bb',
		'vmbo-kb',
		'vmbo-gt',
		'havo',
		'vwo',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OR lifecycle guard entry-point.
	 *
	 * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
	 *                                               - 'object' : SchoolAdvies property array
	 *                                               - 'to'     : target lifecycle state
	 *
	 * @return bool True when the transition may proceed; false blocks it.
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-a-higher-doorstroomtoets-result-without-a-raised-definitief-or-a-motivation-blocks-finalisation
	 */
	public function check(array &$transitionContext): bool {
		$object = $transitionContext['object'] ?? [];

		if ($this->heroverwegingSatisfied(object: $object) === true) {
			return true;
		}

		$this->logger->info(
			'[SchoolAdviesFinalizeGuard] SchoolAdvies {id} — doorstroomtoets outranks the definitief advies '
			. 'without a raise, motivation, or exemption; blocking vaststellenDefinitief.',
			['id' => $object['id'] ?? ($object['uuid'] ?? '')]
		);

		return false;
	}//end check()

	/**
	 * Whether the heroverweging rule is satisfied (finalisation may proceed).
	 *
	 * @param array<string,mixed> $object SchoolAdvies property array.
	 *
	 * @return bool True when finalisation may proceed.
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-raising-definitiefadvieslevel-to-match-the-doorstroomtoets-result-allows-finalisation
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-a-motivation-allows-finalisation-without-raising-the-level
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-the-provmbo-bb-exemption-allows-finalisation-without-a-raise-or-motivation
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-a-doorstroomtoets-result-that-does-not-outrank-the-definitief-advies-never-blocks-finalisation
	 */
	private function heroverwegingSatisfied(array $object): bool {
		$doorstroom = $object['doorstroomtoetsResultLevel'] ?? null;
		$definitief = $object['definitiefAdviesLevel'] ?? null;

		if ($this->doorstroomOutranks(definitief: $definitief, doorstroom: $doorstroom) === false) {
			// Nothing recorded to compare, an unrecognised ordinal value, or a
			// toets that did not score higher — nothing to reconsider.
			return true;
		}

		$motivation = trim((string)($object['heroverwegingMotivation'] ?? ''));
		if ($motivation !== '') {
			return true;
		}

		$voorlopig = $object['voorlopigAdviesLevel'] ?? null;
		if (in_array($voorlopig, ['pro', 'vmbo-bb'], true) === true
			&& in_array($doorstroom, ['pro', 'vmbo-bb'], true) === true
		) {
			return true;
		}

		return false;
	}//end heroverwegingSatisfied()

	/**
	 * Whether the doorstroomtoets result outranks the definitief advies on the
	 * shared ordinal. Returns false — "nothing to reconsider" — whenever the
	 * two values cannot be compared: either missing/non-string, or not on the
	 * ordinal. The guard never blocks finalisation on unreadable data.
	 *
	 * @param mixed $definitief SchoolAdvies.definitiefAdviesLevel.
	 * @param mixed $doorstroom SchoolAdvies.doorstroomtoetsResultLevel.
	 *
	 * @return bool True only when both levels are comparable and the toets scored higher.
	 */
	private function doorstroomOutranks(mixed $definitief, mixed $doorstroom): bool {
		if (is_string($definitief) === false || is_string($doorstroom) === false) {
			return false;
		}

		$definitiefRank = array_search($definitief, self::ORDINAL_LEVELS, true);
		$doorstroomRank = array_search($doorstroom, self::ORDINAL_LEVELS, true);

		if ($definitiefRank === false || $doorstroomRank === false) {
			return false;
		}

		return $doorstroomRank > $definitiefRank;
	}//end doorstroomOutranks()
}//end class
