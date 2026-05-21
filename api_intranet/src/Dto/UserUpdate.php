<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

//Receives and validates the json
class UserUpdate
{
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->email = $data['email'] ?? null;
        $dto->name = $data['name'] ?? null;
        $dto->surname = $data['surname'] ?? null;
        $dto->roles = $data['roles'] ?? null;
        $dto->password = $data['password'] ?? null;
        return $dto;
    }

    /**
     * The ranked roles that are mutually exclusive.
     * Only one of these should be stored on a user at a time.
     */
    private const TIERED_ROLES = ['ROLE_USER', 'ROLE_LOGISTICS', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    /** @Assert\Email */
    public $email;

    /** @Assert\Type("array") */
    public $roles;

    /** @Assert\Type("string") */
    public $name;

    /** @Assert\Type("string") */
    public $surname;

    /**
     * @Assert\Length(
     *      min=6,
     *      minMessage="Password must be at least {{ limit }} characters"
     * )
     */
    public $password;

    /**
     * When a tiered role is being set, strip other tiered roles from the current
     * user roles but keep non-tiered ones (e.g. ROLE_EDITOR).
     */
    private function resolveRoles(array $currentRoles, array $newRoles): array
    {
        $hierarchy = ['ROLE_USER' => 1, 'ROLE_LOGISTICS' => 2, 'ROLE_ADMIN' => 3, 'ROLE_SUPER_ADMIN' => 4];
        $incomingTiered = array_intersect($newRoles, self::TIERED_ROLES);

        $highestTier = null;
        $highestRank = 0;

        foreach ($incomingTiered as $role) {
            if (isset($hierarchy[$role]) && $hierarchy[$role] > $highestRank) {
                $highestRank = $hierarchy[$role];
                $highestTier = $role;
            }
        }

        // Keep non-tiered roles from current roles (e.g., ROLE_EDITOR)
        $preserved = array_filter($currentRoles, fn($r) => !in_array($r, self::TIERED_ROLES));

        // Keep non-tiered roles from new roles
        $newNonTiered = array_filter($newRoles, fn($r) => !in_array($r, self::TIERED_ROLES));

        // Combine preserved and new non-tiered roles
        $finalRoles = array_unique(array_merge(array_values($preserved), array_values($newNonTiered)));

        // Only append the single highest tier role to prevent role accumulation
        if ($highestTier) {
            $finalRoles[] = $highestTier;
        }

        return array_values($finalRoles);
    }

    public function updateEntity($user, $encoder, $canChangeRoles, bool $isSelfUpdate, array $providedFields = []): void
    {
        if (in_array('email', $providedFields) && $this->email !== null) {
            $user->setEmail($this->email);
        }

        if (in_array('password', $providedFields) && !empty($this->password)) {
            if ($encoder->isPasswordValid($user, $this->password)) {
                throw new \InvalidArgumentException('La nueva contraseña no puede ser igual a la anterior.');
            }
            $user->setPassword($encoder->encodePassword($user, $this->password));
            $user->setMustChangePassword(!$isSelfUpdate);
        }

        if (in_array('name', $providedFields)) {
            $user->setName($this->name);
        }

        if (in_array('surname', $providedFields)) {
            $user->setSurname($this->surname);
        }

        if (in_array('roles', $providedFields) && $this->roles !== null && $canChangeRoles) {
            $resolved = $this->resolveRoles($user->getRoles(), $this->roles);
            $user->setRoles($resolved);
        }
    }
}
