<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Domain;

/**
 * The (server-side resolved) identity performing an operation.
 * Roles are never taken from client input.
 */
final class Actor {
	/** @var list<Role> */
	private array $roles;

	/**
	 * @param list<Role> $roles
	 */
	public function __construct(
		public readonly ?string $userId,
		array $roles,
	) {
		$this->roles = array_values(array_unique($roles, SORT_REGULAR));
	}

	public static function anonymous(): self {
		return new self(null, []);
	}

	public function isAnonymous(): bool {
		return $this->userId === null;
	}

	public function has(Role ...$roles): bool {
		foreach ($roles as $role) {
			if (in_array($role, $this->roles, true)) {
				return true;
			}
		}
		return false;
	}

	/** @return list<Role> */
	public function roles(): array {
		return $this->roles;
	}

	/** Secretariat privileges (secretaries and administrators). */
	public function isSecretariat(): bool {
		return $this->has(Role::Secretary, Role::Admin);
	}

	public function hasAnyRole(): bool {
		return $this->roles !== [];
	}

	public function requireUserId(): string {
		if ($this->userId === null) {
			throw new Exception\ForbiddenException('Login påkrævet.');
		}
		return $this->userId;
	}
}
