<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    // Checks the user BEFORE their password is verified
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // If the user's account is deactivated, block login immediately
        if (!$user->isActive()) {
            // The error message is automatically sent to the frontend
            throw new CustomUserMessageAccountStatusException('Esta cuenta ha sido desactivada.');
        }
    }

    // Checks the user AFTER their password is successfully verified
    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Re-check status to ensure deactivated accounts cannot proceed
        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Esta cuenta ha sido desactivada.');
        }
    }
}
