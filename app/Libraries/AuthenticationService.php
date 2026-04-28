<?php

namespace App\Libraries;

use App\Models\UserModel;
use App\Models\RoleModel;
use App\Models\CustomerModel;
use App\Models\BusinessProfileModel;
use App\Models\PasswordResetModel;
use Config\Email;

class AuthenticationService
{
    private UserModel $userModel;
    private RoleModel $roleModel;
    private CustomerModel $customerModel;
    private BusinessProfileModel $businessProfileModel;
    private PasswordResetModel $resetModel;
    private JwtService $jwtService;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->roleModel = new RoleModel();
        $this->customerModel = new CustomerModel();
        $this->businessProfileModel = new BusinessProfileModel();
        $this->resetModel = new PasswordResetModel();
        $this->jwtService = new JwtService();
    }

    /**
     * Register a new user
     *
     * @return array{success: bool, message: string, user?: array, tokens?: array, errors?: array}
     */
    public function register(array $data): array
    {
        $errors = $this->validateRegistration($data);
        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $errors,
            ];
        }

        // Check if email exists
        $existing = $this->userModel->findByEmail($data['email']);
        if (is_array($existing)) {
            return [
                'success' => false,
                'message' => 'Email already registered',
                'errors' => ['email' => 'This email is already registered'],
            ];
        }

        $userData = [
            'email' => trim($data['email']),
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'name' => trim($data['name']),
            'phone' => trim($data['phone'] ?? ''),
            'company' => trim($data['company'] ?? ''),
            'is_active' => true,
        ];

        $userId = $this->userModel->insert($userData) ? $this->userModel->getInsertID() : null;
        if (!$userId) {
            return [
                'success' => false,
                'message' => 'Failed to create user account',
            ];
        }

        // Assign default customer role
        $customerRoleId = $this->roleModel->getOrCreate('Customer', 'customer', 'Regular customer user');
        if ($customerRoleId) {
            $this->userModel->assignRole($userId, $customerRoleId);
        }

        $this->syncCustomerAccount($userId, $data);

        // Get user with roles
        $user = $this->userModel->getUserWithRoles($userId);
        if (!is_array($user)) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve user data',
            ];
        }

        // Generate tokens
        $tokens = $this->generateTokens($user);
        $activeBusinessProfile = $this->businessProfileModel->findActive();
        $user['active_business_profile'] = $activeBusinessProfile;

        return [
            'success' => true,
            'message' => 'User registered successfully',
            'user' => $this->formatUserResponse($user, $activeBusinessProfile),
            'tokens' => $tokens,
        ];
    }

    /**
     * Login user
     *
     * @return array{success: bool, message: string, user?: array, tokens?: array, errors?: array}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->userModel->findByEmail($email);
        if (!is_array($user)) {
            return [
                'success' => false,
                'message' => 'Email not found',
                'errors' => ['email' => 'Email not found'],
            ];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return [
                'success' => false,
                'message' => 'Password is not correct',
                'errors' => ['password' => 'Password is not correct'],
            ];
        }

        // Update last login
        $this->userModel->updateLastLogin($user['id']);

        // Get user with roles
        $user = $this->userModel->getUserWithRoles($user['id']);
        if (!is_array($user)) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve user data',
            ];
        }

        // Generate tokens
        $tokens = $this->generateTokens($user);
        $activeBusinessProfile = $this->getActiveBusinessProfile();


        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => $this->formatUserResponse($user, $activeBusinessProfile),
            'tokens' => $tokens,
        ];
    }

    /**
     * Get the currently authenticated user by ID.
     */
    public function getCurrentUser(int $userId): ?array
    {
        $user = $this->userModel->getUserWithRoles($userId);
        if (!is_array($user)) {
            return null;
        }

        return $this->formatUserResponse($user, $this->getActiveBusinessProfile());
    }

    /**
     * Request password reset
     *
     * @return array{success: bool, message: string}
     */
    public function requestPasswordReset(string $email): array
    {
        $user = $this->userModel->findByEmail($email);
        if (!is_array($user)) {
            // Don't reveal if email exists for security
            return [
                'success' => true,
                'message' => 'If an account exists with this email, password reset instructions have been sent',
            ];
        }

        $token = $this->resetModel->createResetToken($user['id'], $user['email']);
        if (!$token) {
            return [
                'success' => false,
                'message' => 'Failed to generate password reset token',
            ];
        }

        // Queue email notification
        $resetUrl = getenv('APP_URL') . '/reset-password?token=' . urlencode($token);
        $this->queuePasswordResetEmail($user['email'], $user['name'], $resetUrl);

        return [
            'success' => true,
            'message' => 'Password reset instructions have been sent to your email',
        ];
    }

    /**
     * Reset password with token
     *
     * @return array{success: bool, message: string, errors?: array}
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'Validation error',
                'errors' => ['password' => 'Password must be at least 8 characters'],
            ];
        }

        $email = $this->resetModel->getUserEmailFromToken($token);
        if (!$email) {
            return [
                'success' => false,
                'message' => 'Invalid or expired reset token',
                'errors' => ['token' => 'This reset link has expired or is invalid'],
            ];
        }

        $user = $this->userModel->findByEmail($email);
        if (!is_array($user)) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        // Update password
        $updated = $this->userModel->update($user['id'], [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
        ]);

        if (!$updated) {
            return [
                'success' => false,
                'message' => 'Failed to update password',
            ];
        }

        // Mark token as used
        $this->resetModel->markAsUsed($token);

        // Queue email notification
        $this->queuePasswordChangedEmail($user['email'], $user['name']);

        return [
            'success' => true,
            'message' => 'Password has been reset successfully',
        ];
    }

    /**
     * Change password (authenticated user)
     *
     * @return array{success: bool, message: string, errors?: array}
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->userModel->find($userId);
        if (!is_array($user)) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            return [
                'success' => false,
                'message' => 'Current password is incorrect',
                'errors' => ['current_password' => 'Current password is incorrect'],
            ];
        }

        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'Validation error',
                'errors' => ['password' => 'Password must be at least 8 characters'],
            ];
        }

        $updated = $this->userModel->update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
        ]);

        if (!$updated) {
            return [
                'success' => false,
                'message' => 'Failed to update password',
            ];
        }

        // Queue email notification
        $this->queuePasswordChangedEmail($user['email'], $user['name']);

        return [
            'success' => true,
            'message' => 'Password changed successfully',
        ];
    }

    /**
     * Refresh access token
     *
     * @return array{success: bool, message: string, tokens?: array}
     */
    public function refreshToken(array $refreshTokenPayload): array
    {
        if (!isset($refreshTokenPayload['user_id'])) {
            return [
                'success' => false,
                'message' => 'Invalid refresh token',
            ];
        }

        $user = $this->userModel->getUserWithRoles($refreshTokenPayload['user_id']);
        if (!is_array($user)) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        $tokens = $this->generateTokens($user);

        return [
            'success' => true,
            'message' => 'Token refreshed successfully',
            'tokens' => $tokens,
        ];
    }

    /**
     * Validate registration input
     */
    private function validateRegistration(array $data): array
    {
        $errors = [];

        $email = trim($data['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email is required';
        } elseif (strlen($email) > 190) {
            $errors['email'] = 'Email must not exceed 190 characters';
        }

        $name = trim($data['name'] ?? '');
        if ($name === '' || strlen($name) < 2) {
            $errors['name'] = 'Name must be at least 2 characters';
        } elseif (strlen($name) > 160) {
            $errors['name'] = 'Name must not exceed 160 characters';
        }

        $password = $data['password'] ?? '';
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        return $errors;
    }

    /**
     * Generate access and refresh tokens
     */
    private function generateTokens(array $user): array
    {
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'roles' => array_column($user['roles'] ?? [], 'slug'),
        ];

        return [
            'access_token' => $this->jwtService->generateToken($payload),
            'refresh_token' => $this->jwtService->generateRefreshToken($payload),
            'token_type' => 'Bearer',
            'expires_in' => (int) getenv('JWT_EXPIRY_HOURS') ?: 24,
        ];
    }

    /**
     * Format user response
     */
    private function formatUserResponse(array $user, ?array $businessProfile = null): array
    {
        $activeBusinessProfile = $businessProfile ?? $this->getActiveBusinessProfile();

        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'phone' => $user['phone'],
            'company' => $user['company'],
            'is_active' => $user['is_active'],
            'email_verified_at' => $user['email_verified_at'],
            'last_login_at' => $user['last_login_at'],
            'roles' => array_map(static function ($role) {
                return [
                    'id' => $role['id'],
                    'name' => $role['name'],
                    'slug' => $role['slug'],
                ];
            }, $user['roles'] ?? []),
            'business_profile' => $activeBusinessProfile,
            'active_business_profile' => $activeBusinessProfile,
        ];
    }

    private function getActiveBusinessProfile(): ?array
    {
        return $this->businessProfileModel->findActive();
    }

    /**
     * Create or link a customer profile for the registered user.
     *
     * Customers created from quotations keep user_id nullable until the user registers.
     */
    private function syncCustomerAccount(int $userId, array $data): void
    {
        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return;
        }

        $payload = [
            'user_id' => $userId,
            'name' => trim((string) ($data['name'] ?? '')),
            'email' => $email,
            'phone' => trim((string) ($data['phone'] ?? '')),
            'company' => trim((string) ($data['company'] ?? '')),
        ];

        $customer = $this->customerModel->findByEmail($email);
        if (is_array($customer)) {
            $customerId = (int) ($customer['id'] ?? 0);
            if ($customerId > 0) {
                $this->customerModel->update($customerId, $payload);
            }

            return;
        }

        $this->customerModel->insert($payload);
    }

    /**
     * Queue password reset email
     */
    private function queuePasswordResetEmail(string $email, string $name, string $resetUrl): void
    {
        $emailQueue = service('emailQueue');
        $body = $emailQueue->renderTemplate([
            'subject' => 'Reset Your Password',
            'recipientName' => $name,
            'headline' => 'Password Reset Request',
            'contentHtml' => '<p>We received a request to reset your password. Click the link below to proceed:</p>'
                . '<p><a href="' . esc($resetUrl) . '">Reset Password</a></p>'
                . '<p>If you did not request this, please ignore this email.</p>'
                . '<p>This link will expire in 1 hour.</p>',
            'actionText' => 'Reset Password',
            'actionUrl' => $resetUrl,
        ]);

        queue_email_job($email, 'Reset Your Password', $body, [
            'mail_type' => 'html',
        ]);
    }

    /**
     * Queue password changed email
     */
    private function queuePasswordChangedEmail(string $email, string $name): void
    {
        $emailQueue = service('emailQueue');
        $body = $emailQueue->renderTemplate([
            'subject' => 'Your Password Has Been Changed',
            'recipientName' => $name,
            'headline' => 'Password Changed Successfully',
            'contentHtml' => '<p>Your password has been changed successfully.</p>'
                . '<p>If you did not make this change, please reset your password immediately.</p>',
            'actionText' => 'Go to Dashboard',
            'actionUrl' => getenv('APP_URL'),
        ]);

        queue_email_job($email, 'Your Password Has Been Changed', $body, [
            'mail_type' => 'html',
        ]);
    }
}
