<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

//Receives and validates the json
class UserUpdateDto
{
    /** @Assert\Email */
    public $email;

    /** @Assert\Type("array") */
    public $roles;

    public $password;


    public function updateEntity($user, $encoder, $canChangeRoles): void
    {
        // Checks if user wrote a password, if so saves it
        if ($this->email) {
            $user->setEmail($this->email);
        }
        // Checks if user wrote a password, if so saves it
        if ($this->password) {
            $user->setPassword($encoder->encodePassword($user, $this->password));
        }
        // Checks if user wrote a role and has the permission to do so, if so changes it
        if ($this->roles && $canChangeRoles) {
            $user->setRoles($this->roles);
        }
    }
}
