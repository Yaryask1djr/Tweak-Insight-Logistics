<?php

require_once __DIR__ . '/response.php';

final class PasswordPolicy
{
    public static function validate(mixed $password): void
    {
        // PHP's current PASSWORD_DEFAULT is bcrypt, which truncates after 72 bytes.
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            Response::error('Password must contain between 8 and 72 bytes and no null characters.', 422);
        }
    }
}
