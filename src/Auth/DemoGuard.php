<?php

declare(strict_types=1);

namespace App\Auth;

use IamAdty\Empira\Auth\Guard;
use IamAdty\Empira\Auth\User;
use IamAdty\Empira\Data\Db;
use IamAdty\Empira\Http\Request;

/**
 * **This is not authentication.**
 *
 * It signs in whoever names a user id in a query string, which is fine for a
 * demonstration and catastrophically wrong anywhere else. It exists so the
 * wiring in {@see \App\App} can be seen working end to end — a real application
 * replaces it with a {@see \IamAdty\Empira\Auth\SessionGuard} over a
 * {@see \IamAdty\Empira\Auth\DatabaseUserProvider}, and nothing else changes.
 *
 * There is no password anywhere in this app for exactly that reason.
 */
final class DemoGuard implements Guard
{
    public function __construct(private readonly Db $db)
    {
    }

    public function identify(Request $request): ?User
    {
        $id = $request->query['as'] ?? null;

        if (!is_string($id) || !ctype_digit($id)) {
            return null;
        }

        $row = $this->db->first('users', fn($query) => $query->where('id', (int) $id));

        if ($row === null) {
            return null;
        }

        return new User((string) $row['id'], ['name' => $row['name'], 'email' => $row['email']]);
    }
}
