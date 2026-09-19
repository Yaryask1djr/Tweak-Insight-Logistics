<?php

require_once __DIR__ . '/response.php';

/**
 * Server-side authorization policy for the fixed platform roles.
 *
 * This is deliberately evaluated from the user's current database role after
 * JWT validation; a React route guard or a role value supplied by the browser
 * never grants access by itself.
 */
final class AuthorizationPolicy
{
    private const PERMISSIONS = [
        'client' => [
            'account.read_self',
            'delivery.request.create',
            'delivery.request.read_own',
            'tracking.live.read_own',
            'notifications.read_own',
            'kyc.read_own',
            'kyc.submit_own',
        ],
        'delivery' => [
            'account.read_self',
            'driver.offer.read',
            'driver.offer.accept',
            'driver.assignment.read_own',
            'driver.assignment.update_status',
            'driver.assignment.update_location',
            'tracking.live.read_own',
            'driver.earnings.read_own',
            'driver.proof.confirm_assigned',
            'driver.profile.read_own',
            'driver.profile.manage_own',
            'driver.availability.manage_own',
            'driver.documents.manage_own',
            'driver.offer.decline',
            'driver.assignment.report_issue',
            'notifications.read_own',
            'kyc.read_own',
            'kyc.submit_own',
        ],
        'admin' => ['*'],
    ];

    public static function can(string $role, string $permission): bool
    {
        $granted = self::PERMISSIONS[$role] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    /** @param array<string, mixed> $user */
    public static function authorize(array $user, string $permission): void
    {
        $role = (string)($user['role'] ?? '');
        if (!self::can($role, $permission)) {
            Response::forbidden("Your {$role} account is not permitted to perform '{$permission}'.");
        }
    }

    /** @return array<string, string[]> */
    public static function matrix(): array
    {
        return self::PERMISSIONS;
    }
}
