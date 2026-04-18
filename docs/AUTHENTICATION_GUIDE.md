# Authentication System Documentation

## Overview

This is a comprehensive authentication system for the REMS Portal API with the following features:

- **User Registration**: New user sign-ups with validation
- **Login**: Email and password authentication with JWT tokens
- **Password Management**: Forgot password, password reset, and change password
- **Role-Based Access Control**: Customer, Employee, and Admin roles
- **JWT Token Authentication**: Secure API endpoint protection
- **Refresh Tokens**: Token refresh mechanism for extended sessions

## Architecture

### Components

1. **Models**
   - `UserModel`: Manages user data and roles
   - `RoleModel`: Manages system roles
   - `PasswordResetModel`: Manages password reset tokens

2. **Services**
   - `AuthenticationService`: Core authentication logic
   - `JwtService`: JWT token generation and verification

3. **Controllers**
   - `AuthenticationController`: API endpoints for auth operations

4. **Middleware**
   - `JwtAuthMiddleware`: Validates JWT tokens
   - `RoleBasedAccessMiddleware`: Enforces role-based permissions

5. **Helpers**
   - `auth_helper.php`: Convenient auth functions

## Setup

### 1. Environment Variables

Create a `.env` file from `.env.example`:

```bash
cp .env.example .env
```

Update critical settings:

```env
JWT_SECRET_KEY=your-super-secret-key-here-min-32-chars
JWT_EXPIRY_HOURS=24
JWT_REFRESH_EXPIRY_HOURS=168
APP_URL=http://localhost
```

### 2. Run Migrations

```bash
php spark migrate
```

This creates:
- `users` table
- `roles` table
- `user_roles` table (pivot)
- `password_resets` table

### 3. Seed Default Roles

```bash
php spark db:seed RoleSeeder
```

This creates three default roles:
- **customer**: Regular users (default)
- **employee**: Staff members
- **admin**: Administrators

## API Endpoints

### Public Endpoints (No Authentication Required)

#### Register
```http
POST /api/auth/register
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "SecurePassword123!",
  "name": "John Doe",
  "phone": "+14155552671",
  "company": "Acme Corp"
}
```

**Response** (201 Created):
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "name": "John Doe",
      "phone": "+14155552671",
      "company": "Acme Corp",
      "roles": [
        {
          "id": 1,
          "name": "Customer",
          "slug": "customer"
        }
      ]
    },
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer",
    "expires_in": 24
  }
}
```

#### Login
```http
POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "SecurePassword123!"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { ... },
    "access_token": "...",
    "refresh_token": "...",
    "token_type": "Bearer",
    "expires_in": 24
  }
}
```

#### Forgot Password
```http
POST /api/auth/forgot-password
Content-Type: application/json

{
  "email": "user@example.com"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "If an account exists with this email, password reset instructions have been sent"
}
```

#### Reset Password
```http
POST /api/auth/reset-password
Content-Type: application/json

{
  "token": "token_from_email",
  "password": "NewPassword123!"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Password has been reset successfully"
}
```

### Protected Endpoints (Authentication Required)

Add `Authorization` header:
```
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

#### Get Current User
```http
GET /api/auth/me
Authorization: Bearer {access_token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 1,
    "email": "user@example.com",
    "name": "John Doe",
    "roles": [...]
  }
}
```

#### Change Password
```http
POST /api/auth/change-password
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "current_password": "OldPassword123!",
  "password": "NewPassword123!"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Password changed successfully"
}
```

#### Refresh Token
```http
POST /api/auth/refresh
Authorization: Bearer {refresh_token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Token refreshed successfully",
  "data": {
    "access_token": "new_access_token",
    "refresh_token": "new_refresh_token",
    "token_type": "Bearer",
    "expires_in": 24
  }
}
```

#### Logout
```http
POST /api/auth/logout
Authorization: Bearer {access_token}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

## Role-Based Access Control

### Roles

- **customer**: Default role for registered users
- **employee**: For staff members
- **admin**: Full system access

### Assigning Roles

Manually in the database:
```sql
INSERT INTO user_roles (user_id, role_id, created_at) 
VALUES (1, 2, NOW());
```

Or programmatically:
```php
$userModel = new UserModel();
$userModel->assignRole($userId, $roleId);
```

### Checking Roles

In controllers:
```php
$userId = $this->getUserIdFromToken();
$user = $userModel->getUserWithRoles($userId);

if (in_array('admin', array_column($user['roles'], 'slug'))) {
    // Admin-only logic
}
```

Using helpers:
```php
if (has_role('admin')) {
    // Admin-only logic
}

if (is_employee()) {
    // Employee logic
}

if (has_any_role(['admin', 'employee'])) {
    // Admin or employee
}
```

## Using Middleware

### JWT Authentication Middleware

Apply to route groups:
```php
$routes->group('api/admin', ['filter' => 'jwtAuth'], function ($routes) {
    // Routes here require valid JWT token
});
```

### Role-Based Access Control Middleware

Apply with role arguments:
```php
$routes->group('api/admin', 
    ['filter' => 'roleBasedAccess:admin'], 
    function ($routes) {
        // Only admins can access
    }
);
```

Multiple roles:
```php
$routes->group('api/admin',
    ['filter' => 'roleBasedAccess:admin,employee'],
    function ($routes) {
        // Admins or employees can access
    }
);
```

## Helper Functions

### Authentication Helpers

```php
// Get current authenticated user
$user = auth_user($request);

// Get current user ID
$userId = auth_user_id($request);

// Check if authenticated
if (is_authenticated($request)) { }

// Check specific role
if (has_role('admin', $request)) { }

// Check any role
if (has_any_role(['admin', 'employee'], $request)) { }

// Check all roles
if (has_all_roles(['admin', 'employee'], $request)) { }

// Convenience role checks
if (is_admin($request)) { }
if (is_employee($request)) { }
if (is_customer($request)) { }

// Extract bearer token
$token = get_bearer_token($request);

// Verify JWT token
$payload = verify_jwt_token($token);
```

## Example: Protected Controller

```php
<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;

class AdminController extends BaseApiController
{
    public function getDashboard()
    {
        // Get authenticated user
        $user = auth_user($this->request);
        
        if (!has_role('admin', $this->request)) {
            return $this->res->forbidden('Admin access required');
        }

        // Admin-only logic
        return $this->res->ok([
            'user' => $user,
            'dashboard_data' => [...]
        ]);
    }
}
```

## Error Responses

### Validation Error (422)
```json
{
  "success": false,
  "message": "Validation errors",
  "errors": {
    "email": "Valid email is required",
    "password": "Password must be at least 8 characters"
  }
}
```

### Unauthorized (401)
```json
{
  "success": false,
  "message": "Invalid email or password",
  "errors": {
    "auth": "Invalid credentials"
  }
}
```

### Forbidden (403)
```json
{
  "success": false,
  "message": "Insufficient permissions for this action"
}
```

## Security Considerations

1. **JWT Secret Key**: Change `JWT_SECRET_KEY` in `.env` to a strong, random value (minimum 32 characters)
2. **HTTPS**: Always use HTTPS in production
3. **Token Storage**: Store tokens securely on the client (not in localStorage for sensitive apps)
4. **CORS**: Configure CORS properly to limit API access
5. **Rate Limiting**: Implement rate limiting on login endpoints
6. **Password Hashing**: Passwords are hashed using `PASSWORD_BCRYPT`
7. **Token Expiration**: Set reasonable token expiration times
8. **CSRF Protection**: Enable CSRF protection in CodeIgniter config

## Testing with Postman

1. **Register**: POST to `/api/auth/register` with user data
2. **Login**: POST to `/api/auth/login` with credentials
3. **Copy Access Token**: Save the `access_token` from response
4. **Set Authorization Header**: 
   - Click "Authorization" tab
   - Type: Bearer Token
   - Token: Paste your access token
5. **Test Protected Endpoint**: GET `/api/auth/me`

## Troubleshooting

### "Invalid or expired token"
- Check that token hasn't expired
- Verify JWT_SECRET_KEY matches between generation and verification
- Ensure Authorization header format: `Bearer {token}`

### "Database table not found"
- Run migrations: `php spark migrate`
- Run seeder: `php spark db:seed RoleSeeder`

### "User not found after registration"
- Check database connection
- Verify migrations ran successfully
- Check file permissions

### Password reset email not sent
- Verify email configuration in `.env`
- Check email service credentials
- Ensure `EmailQueueService` is working

## Future Enhancements

- Two-factor authentication (2FA)
- OAuth2 integration (Google, GitHub)
- Session management
- Token blacklisting
- Login activity logging
- IP-based security restrictions
- Email verification on registration
