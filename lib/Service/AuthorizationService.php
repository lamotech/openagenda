<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Service;

use OCA\OpenAgenda\Domain\AccessPolicy;
use OCA\OpenAgenda\Domain\Actor;
use OCA\OpenAgenda\Domain\Exception\ForbiddenException;
use OCA\OpenAgenda\Domain\Role;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Central authorization service.
 *
 * Resolves the current user's roles from Nextcloud group membership (never
 * from client input) and enforces the AccessPolicy.
 */
class AuthorizationService {
	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private SettingsService $settings,
		private AccessPolicy $policy,
	) {
	}

	public function policy(): AccessPolicy {
		return $this->policy;
	}

	/** Actor for the logged-in user (anonymous if not logged in). */
	public function currentActor(): Actor {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return Actor::anonymous();
		}
		return $this->actorFor($user->getUID());
	}

	public function actorFor(string $userId): Actor {
		$roles = [];
		if ($this->groupManager->isAdmin($userId)) {
			$roles[] = Role::Admin;
		}
		foreach (Role::cases() as $role) {
			$gid = $this->settings->getGroupForRole($role);
			if ($gid !== '' && $this->groupManager->isInGroup($userId, $gid)) {
				$roles[] = $role;
			}
		}
		return new Actor($userId, $roles);
	}

	/**
	 * Throw unless $allowed. Use with AccessPolicy::can*() results.
	 *
	 * @throws ForbiddenException
	 */
	public function require(bool $allowed, string $message = 'Du har ikke rettighed til denne handling.'): void {
		if (!$allowed) {
			throw new ForbiddenException($message);
		}
	}

	/** @throws ForbiddenException */
	public function requireAppAccess(Actor $actor): void {
		$this->require($this->policy->canUseApp($actor), 'Du har ikke adgang til OpenAgenda. Kontakt en administrator for at få tildelt en rolle.');
	}
}
