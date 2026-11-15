<?php

/**
 * Learniq Import Decision
 *
 * The outcome of the licence rules for one source record: link, copy or
 * refused, with the reason code and the non-commercial flag.
 *
 * @category Service
 * @package  OCA\Learniq\Service\OpenMaterial
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

/**
 * Link, copy or refused, and why.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */
final class ImportDecision {

	public const LINK = 'link';
	public const COPY = 'copy';
	public const REFUSED = 'refused';

	/**
	 * Whether the licence forbids commercial use.
	 *
	 * @var boolean
	 */
	private bool $nonCommercial = false;

	/**
	 * Constructor.
	 *
	 * @param string $mode   link, copy or refused.
	 * @param string $reason The reason code, such as `no-derivatives`.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly string $reason,
	) {
	}//end __construct()

	/**
	 * The same decision, flagged non-commercial or not.
	 *
	 * @param bool $nonCommercial Whether the licence forbids commercial use.
	 *
	 * @return self The flagged decision.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function withNonCommercial(bool $nonCommercial): self {
		$flagged = new self(mode: $this->mode, reason: $this->reason);
		$flagged->nonCommercial = $nonCommercial;

		return $flagged;
	}//end withNonCommercial()

	/**
	 * Whether the licence forbids commercial use.
	 *
	 * @return bool True for NC material.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function isNonCommercial(): bool {
		return $this->nonCommercial;
	}//end isNonCommercial()

	/**
	 * The decision as the API returns it next to a record.
	 *
	 * @return array{mode: string, reason: string, nonCommercial: bool} The decision.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function toArray(): array {
		return ['mode' => $this->mode, 'reason' => $this->reason, 'nonCommercial' => $this->nonCommercial];
	}//end toArray()
}//end class
