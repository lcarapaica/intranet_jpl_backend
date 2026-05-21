<?php

namespace App\Dto;

use App\Entity\User;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class RegisterInput
{
    /**
     * @Assert\NotBlank(message="El email es obligatorio")
     * @Assert\Email(message="El email '{{ value }}' no es un correo válido.")
     */
    public ?string $email = null;

    /**
     * @Assert\NotBlank(message="El nombre es obligatorio")
     */
    public ?string $name = null;

    /**
     * @Assert\NotBlank(message="El apellido es obligatorio")
     */
    public ?string $surname = null;

    public ?string $role = 'ROLE_USER';

    /**
     * @Assert\NotBlank(message="La contraseña es obligatoria")
     * @Assert\Length(
     *     min=6,
     *     minMessage="La contraseña debe tener al menos {{ limit }} caracteres"
     * )
     */
    public ?string $password = null;

    // Populates DTO from request payload array
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->email = $data['email'] ?? null;
        $dto->name = $data['name'] ?? null;
        $dto->surname = $data['surname'] ?? null;
        $dto->role = $data['role'] ?? 'ROLE_USER';
        $dto->password = $data['password'] ?? null;
        return $dto;
    }

    // Creates and configures a new User entity from the validated input
    public function toEntity(UserPasswordEncoderInterface $encoder): User
    {
        $user = new User();
        $user->setEmail($this->email);
        $user->setName($this->name);
        $user->setSurname($this->surname);
        $user->setRoles([$this->role ?? 'ROLE_USER']);
        
        $hashed = $encoder->encodePassword($user, $this->password);
        $user->setPassword($hashed);
        $user->setMustChangePassword(true);

        return $user;
    }
}
