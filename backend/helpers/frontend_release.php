<?php
declare(strict_types=1);

final class FrontendRelease
{
    public static function index(string $public): string
    {
        $pointer = $public . '/.active-release.json';
        if (!is_file($pointer)) return $public . '/index.html'; // One-time legacy transition.
        $release = json_decode(file_get_contents($pointer), true);
        $id = $release['current'] ?? '';
        if (!is_string($id) || !preg_match('/\A[0-9]{14}-[a-f0-9]{12}\z/', $id)) return $public . '/unavailable-release';
        return $public . '/releases/' . $id . '/index.html';
    }
}
