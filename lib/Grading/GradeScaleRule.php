<?php

/**
 * Learniq GradeScaleRule
 *
 * How a school's grade scale rounds a final grade and decides whether it passes.
 *
 * @category Grading
 * @package  OCA\Learniq\Grading
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/grading/spec.md#requirement-the-scale-decides-how-a-final-grade-is-rounded-and-whether-it-passes
 */

declare(strict_types=1);

namespace OCA\Learniq\Grading;

/**
 * The parts of a GradeScale the roll-up reads: its kind, pass limit, rounding rule and bands.
 *
 * A scale without a rounding rule, or with one this class does not know,
 * rounds nothing, so a grade is never changed by a rule nobody declared.
 *
 * @spec openspec/specs/grading/spec.md#requirement-the-scale-decides-how-a-final-grade-is-rounded-and-whether-it-passes
 */
final class GradeScaleRule {

	/**
	 * The kinds whose pass verdict is the pass flag of a band.
	 *
	 * @var array<int, string>
	 */
	public const BAND_KINDS = ['letter', 'ects', 'band'];

	/**
	 * Constructor.
	 *
	 * @param string                           $kind          The scale kind.
	 * @param float|null                       $passThreshold The pass limit of a numeric or percentage scale.
	 * @param string                           $roundingRule  none, half-up-1dp, half-up-int or nearest-half.
	 * @param array<int, array<string, mixed>> $bands         The bands, each with minValue, maxValue and pass.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly ?float $passThreshold,
		public readonly string $roundingRule = 'none',
		public readonly array $bands = [],
	) {
	}//end __construct()

	/**
	 * The rule of a stored GradeScale.
	 *
	 * @param array<string, mixed> $scale The GradeScale object.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-the-scale-decides-how-a-final-grade-is-rounded-and-whether-it-passes
	 */
	public static function fromScale(array $scale): self {
		$threshold = null;
		if (is_numeric($scale['passThreshold'] ?? null) === true) {
			$threshold = (float) $scale['passThreshold'];
		}

		$bands = [];
		foreach ((array) ($scale['bands'] ?? []) as $band) {
			if (is_array($band) === true) {
				$bands[] = $band;
			}
		}

		return new self(
			kind: (string) ($scale['kind'] ?? 'numeric'),
			passThreshold: $threshold,
			roundingRule: (string) ($scale['roundingRule'] ?? 'none'),
			bands: $bands
		);
	}//end fromScale()

	/**
	 * The final value rounded by the scale's rule.
	 *
	 * @param float $value The aggregated value.
	 *
	 * @return float The value as it is compared and stored.
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-the-scale-decides-how-a-final-grade-is-rounded-and-whether-it-passes
	 */
	public function round(float $value): float {
		return match ($this->roundingRule) {
			'half-up-1dp' => round($value, 1, PHP_ROUND_HALF_UP),
			'half-up-int' => round($value, 0, PHP_ROUND_HALF_UP),
			'nearest-half' => (round($value * 2, 0, PHP_ROUND_HALF_UP) / 2),
			default => $value,
		};
	}//end round()

	/**
	 * Whether the scale decides passing by the band a value falls in.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-the-scale-decides-how-a-final-grade-is-rounded-and-whether-it-passes
	 */
	public function passesByBand(): bool {
		return in_array($this->kind, self::BAND_KINDS, true) === true;
	}//end passesByBand()

	/**
	 * Whether a value passes on this scale: by its band's pass flag on a band
	 * scale, else at or above the pass limit (no limit passes).
	 *
	 * A value that falls in no band of a band scale does not pass: the scale
	 * says nothing about it, and a pass is never assumed.
	 *
	 * @param float $value The rounded value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-the-scale-decides-how-a-final-grade-is-rounded-and-whether-it-passes
	 */
	public function passes(float $value): bool {
		if ($this->passesByBand() === false) {
			return $this->passThreshold === null || $value >= $this->passThreshold;
		}

		foreach ($this->bands as $band) {
			if (is_numeric($band['minValue'] ?? null) === true
				&& is_numeric($band['maxValue'] ?? null) === true
				&& $value >= (float) $band['minValue']
				&& $value <= (float) $band['maxValue']
			) {
				return ($band['pass'] ?? false) === true;
			}
		}

		return false;
	}//end passes()
}//end class
