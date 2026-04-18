<?php

use App\Libraries\JwtService;
use App\Models\UserModel;

/**
 * Get current authenticated user from request
 */
function auth_user($request = null)
{
    if ($request === null) {
        $request = service('request');
    }

    if (!isset($request->user)) {
        return null;
    }

    $userId = $request->user['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $userModel = new UserModel();

    return $userModel->getUserWithRoles($userId);
}

/**
 * Get current user ID from request
 */
function auth_user_id($request = null)
{
    if ($request === null) {
        $request = service('request');
    }

    return $request->user['user_id'] ?? null;
}

/**
 * Check if user is authenticated
 */
function is_authenticated($request = null): bool
{
    if ($request === null) {
        $request = service('request');
    }

    return isset($request->user) && !empty($request->user['user_id']);
}

/**
 * Check if user has specific role
 */
function has_role(string $role, $request = null): bool
{
    if ($request === null) {
        $request = service('request');
    }

    if (!isset($request->user)) {
        return false;
    }

    $roles = $request->user['roles'] ?? [];

    return in_array($role, $roles, true);
}

/**
 * Check if user has any of the provided roles
 */
function has_any_role(array $roles, $request = null): bool
{
    if ($request === null) {
        $request = service('request');
    }

    if (!isset($request->user)) {
        return false;
    }

    $userRoles = $request->user['roles'] ?? [];

    foreach ($roles as $role) {
        if (in_array($role, $userRoles, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Check if user has all provided roles
 */
function has_all_roles(array $roles, $request = null): bool
{
    if ($request === null) {
        $request = service('request');
    }

    if (!isset($request->user)) {
        return false;
    }

    $userRoles = $request->user['roles'] ?? [];

    foreach ($roles as $role) {
        if (!in_array($role, $userRoles, true)) {
            return false;
        }
    }

    return true;
}

/**
 * Check if user is admin
 */
function is_admin($request = null): bool
{
    return has_role('admin', $request);
}

/**
 * Check if user is employee
 */
function is_employee($request = null): bool
{
    return has_role('employee', $request);
}

/**
 * Check if user is customer
 */
function is_customer($request = null): bool
{
    return has_role('customer', $request);
}

/**
 * Extract token from Authorization header
 */
function get_bearer_token($request = null): ?string
{
    if ($request === null) {
        $request = service('request');
    }

    $authHeader = $request->getHeaderLine('Authorization');

    return JwtService::extractToken($authHeader);
}

/**
 * Verify and decode JWT token
 */
function verify_jwt_token(string $token): ?array
{
    $jwtService = new JwtService();

    return $jwtService->verifyAndDecode($token);
}
