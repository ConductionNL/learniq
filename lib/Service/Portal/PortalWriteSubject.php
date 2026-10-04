<?php

/**
 * Learniq PortalWriteSubject
 *
 * Tells a portal write from a staff write, for the listeners that stamp the
 * owner of a Submission and of an ExcuseRequest.
 *
 * WHY THIS IS NOT "NOBODY IS SIGNED IN". Both listeners used to decide it that
 * way, which was right while every portal citizen was a DigiD guardian with no
 * Nextcloud account. A PUPIL signs in to her portal with her school account
 * (portaliq's `nextcloud` mode), so her browser carries a Nextcloud session
 * cookie, her own hand-in and her own absence report were read as staff writes
 * and were refused. Measured on a live instance on 4 October 2026
 * (tests/e2e/pupil-flows.spec.ts, steps c and d): the identical requests with
 * the identical bearer succeeded from a cookie-less context and failed from
 * her own browser.
 *
 * So the question is not whether somebody is signed in, but WHO. A write in
 * the portal's shape belongs to the person it is about: the pupil whose work
 * or absence it is, or the guardian reporting for their child. Anybody else's
 * session keeps the staff branch, which is what the old rule protected — a
 * teacher must not hand work in in a pupil's name by sending a `learnerRef`.
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Service\LearnerRefResolver;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether a write in the portal's shape was made by the person it is about.
 *
 * @spec openspec/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
 */
class PortalWriteSubject {

	/**
	 * Constructor.
	 *
	 * @param LearnerRefResolver $profiles    Resolves a profile uuid to its account.
	 * @param IUserSession       $userSession The Nextcloud session, if any.
	 * @param LoggerInterface    $logger      PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerRefResolver $profiles,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this write is the portal's own.
	 *
	 * True when nobody is signed in — the portal receiver's own case — or when
	 * the one who is, is the person named by `$ref`. A lookup that fails says
	 * no: a write that cannot be attributed takes the staff branch, where it
	 * has to name its owner itself.
	 *
	 * @param string $ref The profile uuid the write is attributed to.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
	 */
	public function wroteItThemselves(string $ref): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return true;
		}

		if ($ref === '') {
			return false;
		}

		try {
			$profile = $this->profiles->byRef(learnerRef: $ref);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[PortalWriteSubject] Could not check who is writing: {msg}',
				['msg' => $exception->getMessage()]
			);
			return false;
		}

		return $profile !== null && (string)($profile['ncUserId'] ?? '') === $user->getUID();
	}//end wroteItThemselves()
}//end class
