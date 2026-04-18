# Authentication System - Quick Reference

## 🚀 Quick Start (5 Minutes)

### 1. Setup
```bash
copy .env.example .env
# Edit .env - set JWT_SECRET_KEY and database config
php spark migrate
php spark db:seed RoleSeeder
```

### 2. Test Registration
```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "SecurePass123!",
    "name": "John Doe"
  }'
```

### 3. Test Login
```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "SecurePass123!"
  }'
```

### 4. Use Access Token
```bash
curl -X GET http://localhost:8080/api/auth/me \
  -H "Authorization: Bearer {access_token}"
```

---

## 📚 API Quick Reference

### Authentication Endpoints
```
POST   /api/auth/register           # Sign up
POST   /api/auth/login              # Sign in
POST   /api/auth/forgot-password    # Request reset
POST   /api/auth/reset-password     # Confirm reset
POST   /api/auth/refresh            # Get new tokens
POST   /api/auth/change-password    # Update password
GET    /api/auth/me                 # Current user
POST   /api/auth/logout             # Sign out
```

---

## 💻 Using in Your Controller

### Basic Authentication Check
```php
public function myEndpoint()
{
    if (!is_authenticated($this->request)) {
        return $this->res->unauthorized('Login required');
    }
    
    // Your code here
    return $this->res->ok(['data' => []]);
}
```

### Role-Based Access
```php
public function adminOnly()
{
    if (!has_role('admin', $this->request)) {
        return $this->res->forbidden('Admin only');
    }
    
    // Your code here
}
```

### Get Current User
```php
$user = auth_user($this->request);      // Full user object
$userId = auth_user_id($this->request); // Just ID
$email = $user['email'];
$roles = $user['roles'];
```

---

## 🛡️ Helper Functions Cheat Sheet

```php
// Check if logged in
is_authenticated($request)

// Get user data
auth_user($request)
auth_user_id($request)

// Check roles
has_role('admin', $request)
has_any_role(['admin', 'employee'], $request)
is_admin($request)
is_employee($request)
is_customer($request)

// Token operations
get_bearer_token($request)
verify_jwt_token($token)
```

---

## 🔐 Default Roles

| Role | Slug | Usage |
|------|------|-------|
| Customer | `customer` | Regular users (default) |
| Employee | `employee` | Staff members |
| Admin | `admin` | Full system access |

---

## 📊 Response Format

### Success (200)
```json
{
  "success": true,
  "message": "Operation successful",
  "data": { "key": "value" }
}
```

### Created (201)
```json
{
  "success": true,
  "message": "Resource created",
  "data": { ... }
}
```

### Error (4xx)
```json
{
  "success": false,
  "message": "Error message",
  "errors": { "field": "error detail" }
}
```

---

## 🔄 Token Format

### Access Token
```
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

Expires in: 24 hours (configurable)

### Refresh Token
```
Use in Authorization header just like access token
```

Expires in: 7 days (configurable)

---

## 🗄️ Database Quick Reference

### users table
```
id, email (UNIQUE), password_hash, name, phone, company, 
is_active, email_verified_at, last_login_at, created_at, updated_at, deleted_at
```

### roles table
```
id, name, slug (UNIQUE), description, created_at, updated_at
```

### user_roles table
```
id, user_id (FK), role_id (FK), created_at
```

### password_resets table
```
id, user_id (FK), email, token_hash, expires_at, used_at, created_at
```

---

## ⚙️ Configuration Essentials

### .env Settings
```env
# REQUIRED - Change this!
JWT_SECRET_KEY=your-min-32-char-secret-key

# Optional defaults
JWT_EXPIRY_HOURS=24
JWT_REFRESH_EXPIRY_HOURS=168

# Database
database.default.hostname=localhost
database.default.database=rems_portal
database.default.username=root
```

---

## 🧪 Testing with Postman

1. Import: `docs/postman/Authentication-API.postman_collection.json`
2. Register a user
3. Copy `access_token` from response
4. Click "Authorization" tab
5. Select "Bearer Token" type
6. Paste token
7. Test protected endpoints

---

## ❌ Common Errors & Fixes

### "Invalid or expired token"
→ Token may have expired (24h default)
→ Use `/api/auth/refresh` to get new token
→ Check JWT_SECRET_KEY is same

### "Unauthorized"
→ No Authorization header provided
→ Format: `Authorization: Bearer {token}`

### "Forbidden"
→ User doesn't have required role
→ Check role was assigned in database

### "SQLSTATE error"
→ Migrations not run: `php spark migrate`
→ Or seeder not run: `php spark db:seed RoleSeeder`

### Email not sending
→ Check email config in `.env`
→ EmailQueueService must be working
→ Check queue logs

---

## 📋 Common Tasks

### Assign Role to User
```php
$userModel = new UserModel();
$userModel->assignRole($userId, $roleId);

// Or SQL:
INSERT INTO user_roles (user_id, role_id, created_at) 
VALUES (1, 2, NOW());
```

### Check if User has Role
```php
$userModel = new UserModel();
$hasRole = $userModel->hasRole($userId, 'admin');
```

### Get User with Roles
```php
$userModel = new UserModel();
$user = $userModel->getUserWithRoles($userId);
// $user['roles'] is array of roles
```

### Find User by Email
```php
$userModel = new UserModel();
$user = $userModel->findByEmail('user@example.com');
```

---

## 🔒 Security Checklist

- [ ] JWT_SECRET_KEY is set to strong random value
- [ ] Using HTTPS in production
- [ ] Email configuration works
- [ ] Database backups in place
- [ ] Rate limiting on login (recommended)
- [ ] CORS configured properly
- [ ] Error messages don't reveal sensitive info
- [ ] Tokens stored securely on client
- [ ] Password requirements enforced

---

## 📖 Full Documentation

- **AUTHENTICATION_GUIDE.md** - Complete API docs
- **IMPLEMENTATION_GUIDE.md** - Setup instructions
- **ARCHITECTURE.md** - System design
- **FILE_SUMMARY.md** - All files created
- **ExampleUsageController.php** - Code examples

---

## 🎯 Workflow Example

```
1. User Registration
   POST /api/auth/register
   → User created
   → Customer role assigned
   → Tokens returned

2. User Login
   POST /api/auth/login
   → Password verified
   → Tokens generated
   → User data returned

3. Authenticated Request
   GET /api/auth/me
   Header: Authorization: Bearer {token}
   → Token verified
   → User data returned

4. Token Refresh
   POST /api/auth/refresh
   Header: Authorization: Bearer {refresh_token}
   → New tokens generated
   → Old tokens invalidated

5. Password Change
   POST /api/auth/change-password
   Header: Authorization: Bearer {token}
   → Password updated
   → Confirmation email sent

6. Password Reset Flow
   POST /api/auth/forgot-password
   → Email sent with reset link
   
   POST /api/auth/reset-password
   → Password updated
   → Confirmation email sent
```

---

## 💡 Pro Tips

1. **Use environment variables** for secrets - never hardcode
2. **Check roles in middleware** for protected routes
3. **Always validate input** even for authenticated users
4. **Log security events** for debugging
5. **Use helper functions** instead of accessing $request->user directly
6. **Store tokens on client** - frontend responsibility
7. **Test role combinations** - admin, employee, customer
8. **Document required roles** in code comments
9. **Use consistent error responses** via ResponseService
10. **Keep migration order** - don't modify completed migrations

---

**For more details, see the full documentation files in the `docs/` directory.**
