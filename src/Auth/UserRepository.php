<?php

declare(strict_types=1);

namespace App\Auth;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use IamAdty\Empira\Auth\DoctrineUserProvider;
use IamAdty\Empira\Auth\User;

/**
 * Reading and writing users.
 *
 * Registration lives here rather than on the provider because an authentication
 * scheme should not decide what a sign-up form is allowed to create. The
 * provider still answers every question auth asks — who is id 7, is this their
 * password — and this is the application sitting on top of it.
 *
 * Writing goes through the ORM, which is what it is for: the unit of work
 * decides what to insert and what to leave alone, and the unique index is
 * enforced by the database rather than by a check-then-write that two requests
 * can both pass.
 */
final class UserRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entities,
        private readonly DoctrineUserProvider $users,
    ) {
    }

    public function byEmail(string $email): ?User
    {
        return $this->users->byCredential($email);
    }

    public function verify(User $user, string $password): bool
    {
        return $this->users->verifyPassword($user, $password);
    }

    /**
     * Create a user and return it, persisted.
     *
     * The unique index on the email is what actually prevents a duplicate; the
     * check before it only produces a readable message in the common case. Two
     * concurrent registrations can still race past it, so the violation is
     * caught rather than left to become a 500 — a race should end in "that
     * email is taken", not a stack trace.
     *
     * @throws DuplicateEmail
     */
    public function register(string $name, string $email, string $password): User
    {
        if ($this->byEmail($email) !== null) {
            throw new DuplicateEmail();
        }

        $user = new User($email, $name, $this->users->hash($password));

        try {
            $this->entities->persist($user);
            $this->entities->flush();
        } catch (UniqueConstraintViolationException) {
            // DBAL's exception, not the ORM's. There is no
            // `EntityUniqueConstraintViolationException` in ORM 3 — a `catch`
            // naming a class that does not exist compiles fine and silently
            // never matches, so this race would have become a 500 rather than a
            // readable message, and only under concurrency, which is the worst
            // time to find out.
            throw new DuplicateEmail();
        }

        return $user;
    }
}