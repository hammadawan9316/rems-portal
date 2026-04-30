<?php

namespace App\Controllers\Api;

use App\Libraries\AuthenticationService;
use App\Libraries\JwtService;

class AuthenticationController extends BaseApiController
{
    private AuthenticationService $authService;
    private JwtService $jwtService;

    public function __construct()
    {
        parent::__construct();
        $this->authService = new AuthenticationService();
        $this->jwtService = new JwtService();
    }

    /**
     * Register new user
     * POST /api/auth/register
     */
    public function register()
    {
        $data = $this->getRequestData(false);

        $result = $this->authService->register($data);

        if (!$result['success']) {
            if (isset($result['errors'])) {
                return $this->res->validation($result['errors']);
            }
            return $this->res->badRequest($result['message']);
        }

        return $this->res->created([
            'user' => $result['user'] ?? null,
            'access_token' => $result['tokens']['access_token'] ?? null,
            'refresh_token' => $result['tokens']['refresh_token'] ?? null,
            'token_type' => $result['tokens']['token_type'] ?? 'Bearer',
            'expires_in' => $result['tokens']['expires_in'] ?? null,
        ], $result['message']);
    }

    /**
     * Login user
     * POST /api/auth/login
     */
    public function login()
    {
        $data = $this->getRequestData(false);

        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if ($email === '' || $password === '') {
            return $this->res->badRequest('Email and password are required', [
                'email' => $email === '' ? 'Email is required' : null,
                'password' => $password === '' ? 'Password is required' : null,
            ]);
        }

        $result = $this->authService->login($email, $password);

        if (!$result['success']) {
            if (isset($result['errors'])) {
                return $this->res->validation($result['errors']);
            }
            return $this->res->unauthorized($result['message']);
        }

        return $this->res->ok([
            'user' => $result['user'] ?? null,
            'access_token' => $result['tokens']['access_token'] ?? null,
            'refresh_token' => $result['tokens']['refresh_token'] ?? null,
            'token_type' => $result['tokens']['token_type'] ?? 'Bearer',
            'expires_in' => $result['tokens']['expires_in'] ?? null,
        ], $result['message']);
    }

    /**
     * Refresh access token
     * POST /api/auth/refresh
     */
    public function refresh()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        $token = JwtService::extractToken($authHeader);

        if (!$token) {
            return $this->res->unauthorized('Authorization token required');
        }

        $payload = $this->jwtService->verifyAndDecode($token);
        if (!is_array($payload)) {
            return $this->res->unauthorized('Invalid or expired token');
        }

        $result = $this->authService->refreshToken($payload);

        if (!$result['success']) {
            return $this->res->unauthorized($result['message']);
        }

        return $this->res->ok([
            'access_token' => $result['tokens']['access_token'] ?? null,
            'refresh_token' => $result['tokens']['refresh_token'] ?? null,
            'token_type' => $result['tokens']['token_type'] ?? 'Bearer',
            'expires_in' => $result['tokens']['expires_in'] ?? null,
        ], $result['message']);
    }

    /**
     * Request password reset
     * POST /api/auth/forgot-password
     */
    public function forgotPassword()
    {
        $data = $this->getRequestData(false);
        $email = trim($data['email'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->res->badRequest('Valid email is required', [
                'email' => 'Valid email is required',
            ]);
        }

        $result = $this->authService->requestPasswordReset($email);

        if (!$result['success']) {
            return $this->res->badRequest($result['message']);
        }

        return $this->res->ok([], $result['message']);
    }

    /**
     * Reset password with token
     * POST /api/auth/reset-password
     */
    public function resetPassword()
    {
        $data = $this->getRequestData(false);
        $token = trim($data['token'] ?? '');
        $newPassword = $data['password'] ?? '';

        if ($token === '') {
            return $this->res->badRequest('Reset token is required', [
                'token' => 'Reset token is required',
            ]);
        }

        if ($newPassword === '') {
            return $this->res->badRequest('New password is required', [
                'password' => 'New password is required',
            ]);
        }

        $result = $this->authService->resetPassword($token, $newPassword);

        if (!$result['success']) {
            if (isset($result['errors'])) {
                return $this->res->validation($result['errors']);
            }
            return $this->res->badRequest($result['message']);
        }

        return $this->res->ok([], $result['message']);
    }

    /**
     * Change password (authenticated)
     * POST /api/auth/change-password
     */
    public function changePassword()
    {
        $userId = $this->getUserIdFromToken();
        if ($userId === null) {
            return $this->res->unauthorized('Authentication required');
        }

        $data = $this->getRequestData(false);
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['password'] ?? '';

        if ($currentPassword === '') {
            return $this->res->badRequest('Current password is required', [
                'current_password' => 'Current password is required',
            ]);
        }

        if ($newPassword === '') {
            return $this->res->badRequest('New password is required', [
                'password' => 'New password is required',
            ]);
        }

        $result = $this->authService->changePassword($userId, $currentPassword, $newPassword);

        if (!$result['success']) {
            if (isset($result['errors'])) {
                return $this->res->validation($result['errors']);
            }
            return $this->res->badRequest($result['message']);
        }

        return $this->res->ok([], $result['message']);
    }

    /**
     * Update current authenticated user profile
     * POST /api/auth/profile
     */
    public function updateProfile()
    {
        $userId = $this->getUserIdFromToken();
        if ($userId === null) {
            return $this->res->unauthorized('Authentication required');
        }

        $data = $this->getRequestData(false);
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = $data['name'];
        }

        if (array_key_exists('phone', $data)) {
            $payload['phone'] = $data['phone'];
        }

        if (array_key_exists('profile_image', $data)) {
            $payload['profile_image'] = $data['profile_image'];
        }

        $uploadedImage = $this->uploadProfileImage();
        if (isset($uploadedImage['error'])) {
            return $this->res->validation(['profile_image' => (string) $uploadedImage['error']]);
        }

        if (isset($uploadedImage['path'])) {
            $payload['profile_image'] = $uploadedImage['path'];
        }

        if ($payload === []) {
            return $this->res->badRequest('No profile fields were provided for update.');
        }

        $result = $this->authService->updateProfile($userId, $payload);
        if (!$result['success']) {
            if (isset($result['errors'])) {
                return $this->res->validation($result['errors']);
            }

            return $this->res->badRequest($result['message']);
        }

        return $this->res->ok([
            'user' => $result['user'] ?? null,
        ], $result['message']);
    }

    /**
     * Get current authenticated user
     * GET /api/auth/me
     */
    public function getCurrentUser()
    {
        $user = $this->getCurrentAuthenticatedUser();
        if ($user === null) {
            return $this->res->unauthorized('Authentication required');
        }

        return $this->res->ok($user);
    }

    /**
     * Logout (optional - mainly for frontend to clear tokens)
     * POST /api/auth/logout
     */
    public function logout()
    {
        $userId = $this->getUserIdFromToken();
        if ($userId === null) {
            return $this->res->unauthorized('Authentication required');
        }

        $authHeader = $this->request->getHeaderLine('Authorization');
        $token = JwtService::extractToken($authHeader);
        if ($token !== null) {
            $this->jwtService->revokeToken($token);
        }

        // Frontend should clear tokens. Optionally, you could blacklist tokens here.
        return $this->res->ok([], 'Logged out successfully');
    }

    /**
     * Helper: Get user ID from JWT token
     */
    protected function getUserIdFromToken(): ?int
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        $token = JwtService::extractToken($authHeader);

        if (!$token) {
            return null;
        }

        $payload = $this->jwtService->verifyAndDecode($token);
        if (!is_array($payload)) {
            return null;
        }

        return $payload['user_id'] ?? null;
    }

    /**
     * Helper: Get current authenticated user
     */
    protected function getCurrentAuthenticatedUser(): ?array
    {
        $userId = $this->getUserIdFromToken();
        if ($userId === null) {
            return null;
        }

        return $this->authService->getCurrentUser($userId);
    }

    /**
     * @return array{path?:string,error?:string}
     */
    private function uploadProfileImage(): array
    {
        $file = $this->request->getFile('profile_image');
        if ($file === null) {
            return [];
        }

        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            return [];
        }

        $directory = 'users/profile/' . date('Y') . '/' . date('m');
        $result = $this->uploadService->uploadSingle(
            $this->request,
            'profile_image',
            $directory,
            ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            5120
        );

        if (($result['status'] ?? false) !== true) {
            $errors = $result['errors'] ?? [];
            $errorMessage = is_array($errors)
                ? (string) ($errors['profile_image'] ?? $result['message'] ?? 'Image upload failed.')
                : (string) ($result['message'] ?? 'Image upload failed.');

            return ['error' => $errorMessage];
        }

        $uploadData = $result['data'] ?? [];
        $relativePath = trim((string) ($uploadData['relative_path'] ?? ''));
        if ($relativePath === '') {
            return ['error' => 'Image upload failed due to missing file path.'];
        }

        return ['path' => $relativePath];
    }
}
