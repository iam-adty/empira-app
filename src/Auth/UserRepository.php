<?php

declare(strict_types=1);

namespace App\Auth;

use IamAdty\Empira\Auth\DatabaseUserProvider;
use IamAdty\Empira\Auth\User;
use IamAdty\Empira\Data\Db;

/**
 * Reading and writing users.
 *
 * `DatabaseUserProvider` deliberately does not write — an authentication
 * scheme should not decide what your sign-up form is allowed to create — so
 * registration lives here, on the application side of the seam.
 *
 * Everything a guard needs to *identify* someone still comes from the
 * provider, so swapping this for an ORM-backed one later changes nothing in
 * authentication: the guards ask a `UserProvider`, and this is one.
 */
final class UserRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly DatabaseUserProvider $users,
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
     * Create a user and sign them straight in.
     *
     * The unique index on the email is what actually prevents a duplicate; the
     * check before it only exists to produce a readable message in the common
     * case. Two concurrent registrations can still race past it, so the
     * violation is caught rather than left to become a 500 — a race should end
     * in "that email is taken", not a stack trace.
     *
     * @throws DuplicateEmail
     */
    public function register(string $name, string $email, string $password): User
    {
        if ($this->byEmail($email) !== null) {
            throw new DuplicateEmail();
        }

        try {
            $this->db->insert('users', [
                'email' => $email,
                'name' => $name,
                'password_hash' => $this->users->hash($password),
            ]);
        } catch (\PDOException $e) {
            if (self::isUniqueViolation($e)) {
                throw new DuplicateEmail();
            }

            throw $e;
        }

        $user = $this->byEmail($email);

        if ($user === null) {
            throw new \RuntimeException('The user was written but could not be read back.');
        }

        return $user;
    }

    /**
     * Is this the driver complaining about a unique index?
     *
     * SQLite says `UNIQUE constraint failed` and PostgreSQL says `duplicate key
     * value violates unique constraint`; both are matched rather than caught
     * wholesale, so an unrelated database error is not reported as a duplicate
     * email.
     */
    private static function isUniqueViolation(\PDOException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'duplicate key value violates unique constraint');
    }
}
