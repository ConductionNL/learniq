<?php

/**
 * Contract of GuardianPortalInvitation
 *
 * The public surface of GuardianPortalInvitation that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of GuardianPortalInvitation.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GuardianPortalInvitationInterface {

	/**
	 * The invitation mail left.
	 */
	public const MAIL_SENT = 'sent';

	/**
	 * Portaliq made the invitation but its mail did not leave.
	 */
	public const MAIL_NOT_SENT = 'not-sent';

	/**
	 * This portaliq sends no invitation mail (an older version), or it
	 * refused this account.
	 */
	public const MAIL_UNAVAILABLE = 'unavailable';

	/**
	 * Portaliq made a code for a paper letter; the answer carries it.
	 */
	public const LETTER_CODE = 'code';

	/**
	 * The invitation goes out by mail, inside a link.
	 */
	public const CHANNEL_MAIL = 'mail';

	/**
	 * The invitation goes out on paper, as a short code the school prints.
	 */
	public const CHANNEL_LETTER = 'letter';

	/**
	 * Invite one guardian to the portal.
	 *
	 * The email address must be one the school verified with the guardian
	 * (it is passed to portaliq as verified, which is what lets the first
	 * login find the account). Returns `status: invited` with the account's
	 * `subjectRef`, or `status: refused` with a `reason`.
	 *
	 * Once the account is linked, portaliq is asked to mail the guardian a
	 * one-time link. `invitation` says what came of it: `sent`, `not-sent`
	 * (the mail did not leave; inviting again sends a new one) or
	 * `unavailable` (this portaliq sends none). The link never comes back
	 * here, so nobody at the school sees it. The guardian is linked either
	 * way: a first sign-in with the verified address still finds the account.
	 *
	 * On the channel `letter` no mail is sent. Portaliq answers a short
	 * one-time code instead, and `invitation` is `code` with the code under
	 * `code` and its expiry under `expiresAt`. The school prints it in a
	 * letter; the guardian signs in and types it on "My account".
	 *
	 * @param string $guardianRef The guardian's LearnerProfile uuid.
	 * @param string $email The guardian's verified email address.
	 * @param string $organisation The portal organisation slug.
	 * @param string $channel CHANNEL_MAIL or CHANNEL_LETTER.
	 * @param string $issuedBy Who issued it: the staff user's uid, or `occ`.
	 *
	 * @return array{status: string, reason?: string, subjectRef?: string, invitation?: string, code?: string, expiresAt?: string}
	 *
	 * @spec openspec/changes/portal-guardian-invitation/specs/portal-identity/spec.md
	 * @spec openspec/changes/portal-guardian-invitation-mail/specs/portal-identity/spec.md
	 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
	 */
	public function invite(string $guardianRef, string $email, string $organisation, string $channel=self::CHANNEL_MAIL, string $issuedBy=''): array;
}//end interface
