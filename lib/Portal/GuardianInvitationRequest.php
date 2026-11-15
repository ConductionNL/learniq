<?php

/**
 * Learniq guardian invitation request
 *
 * One invitation as GuardianPortalInvitation::invite() works through it: the
 * guardian asked for, the trimmed address and organisation, the channel, the
 * issuer, and the guardian's profile once it is found. Built and read only
 * inside GuardianPortalInvitation, for the length of one invite.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * One guardian invitation, step by step.
 *
 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
 */
final class GuardianInvitationRequest {

	/**
	 * The guardian's LearnerProfile uuid, as the caller gave it.
	 *
	 * @var string
	 */
	public string $guardianRef;

	/**
	 * The guardian's verified email address, trimmed.
	 *
	 * @var string
	 */
	public string $email;

	/**
	 * The portal organisation slug, trimmed.
	 *
	 * @var string
	 */
	public string $organisation;

	/**
	 * CHANNEL_MAIL or CHANNEL_LETTER, as asked for.
	 *
	 * @var string
	 */
	public string $channel;

	/**
	 * Who issued it: the staff user's uid, `occ`, or ''.
	 *
	 * @var string
	 */
	public string $issuedBy;

	/**
	 * The guardian's profile, once found.
	 *
	 * @var array<string, mixed>
	 */
	public array $guardian;
}//end class
