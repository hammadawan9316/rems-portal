# REMS Portal Authentication Architecture

## System Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         CLIENT APPLICATION                       │
│  (Web/Mobile - Sends HTTP Requests)                              │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│                    API GATEWAY / ROUTES                          │
│  Routes.php - Defines all endpoints and middleware               │
└────────────────────────┬────────────────────────────────────────┘
                         │
          ┌──────────────┼──────────────┐
          │              │              │
          ▼              ▼              ▼
    ┌──────────┐  ┌──────────┐  ┌──────────┐
    │ Public   │  │Protected │  │Protected │
    │ Routes   │  │ Routes   │  │ Routes   │
    │ (No Auth)│  │ (JWT)    │  │ (JWT +   │
    │          │  │          │  │ Role)    │
    └────┬─────┘  └────┬─────┘  └────┬─────┘
         │             │             │
         │      ┌──────▼──────┐      │
         │      │   Middleware│      │
         │      │   Pipeline  │      │
         │      └──────┬───────┘      │
         │             │              │
    ┌────┴─────────────┴──────────────┴────┐
    │                                       │
    ▼                                       ▼
┌──────────────────────┐        ┌──────────────────────┐
│ JwtAuthMiddleware    │        │ RoleBasedAccess      │
│ - Extract Token      │        │ Middleware           │
│ - Verify Signature   │        │ - Check User Roles   │
│ - Validate Expiry    │        │ - Grant/Deny Access  │
│ - Store User in Req  │        │                      │
└──────────┬───────────┘        └──────────┬───────────┘
           │                               │
           └───────────┬───────────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │ AuthenticationController      │
        │ - register()                 │
        │ - login()                    │
        │ - forgotPassword()           │
        │ - resetPassword()            │
        │ - changePassword()           │
        │ - refresh()                  │
        │ - getCurrentUser()           │
        │ - logout()                   │
        └──────────────┬───────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │ AuthenticationService        │
        │ - register(data)             │
        │ - login(email, password)     │
        │ - changePassword(...)        │
        │ - requestPasswordReset(...)  │
        │ - resetPassword(token, pwd)  │
        │ - refreshToken(payload)      │
        └────────┬─────────────────────┘
                 │
     ┌───────────┼───────────┐
     │           │           │
     ▼           ▼           ▼
┌────────┐  ┌────────┐  ┌────────────┐
│ JWT    │  │ User   │  │ Password   │
│Service │  │Model   │  │ Reset      │
│        │  │        │  │ Model      │
├────────┤  ├────────┤  ├────────────┤
│Generate│  │Find    │  │Create      │
│Token   │  │User    │  │Reset Token │
│        │  │        │  │            │
│Verify  │  │Assign  │  │Mark Used   │
│Token   │  │Role    │  │            │
│        │  │        │  │Cleanup     │
└────────┘  └────────┘  └────────────┘
     │           │           │
     └───────────┼───────────┘
                 │
                 ▼
        ┌──────────────────────────────┐
        │       DATABASE               │
        │  ┌─────────────────────────┐ │
        │  │ users                   │ │
        │  │ - id, email, password   │ │
        │  │ - name, phone, company  │ │
        │  └─────────────────────────┘ │
        │  ┌─────────────────────────┐ │
        │  │ roles                   │ │
        │  │ - id, name, slug        │ │
        │  └─────────────────────────┘ │
        │  ┌─────────────────────────┐ │
        │  │ user_roles (pivot)      │ │
        │  │ - user_id, role_id      │ │
        │  └─────────────────────────┘ │
        │  ┌─────────────────────────┐ │
        │  │ password_resets         │ │
        │  │ - token, expires_at     │ │
        │  └─────────────────────────┘ │
        └──────────────────────────────┘
```

## Component Interaction

```
Client Request
    │
    ├─ Public Route? ──────────► Direct to Controller
    │                                   │
    │                                   ▼
    │                           Process Request
    │                                   │
    │                                   ▼
    │                           Send Response
    │
    └─ Protected Route? ────────► JwtAuthMiddleware
                                         │
                                    Valid Token? ◄────No──► Return 401
                                         │
                                        Yes
                                         │
                                         ▼
                                    Role Check?
                                         │
                            ┌────────────┼────────────┐
                            │            │            │
                           No           Yes        Not Required
                            │            │            │
                            ▼            ▼            ▼
                       Check Roles  Roles Valid?  Controller
                            │            │            │
                            │       ┌────┴────┐       │
                            │       │          │       │
                    No ◄─────┘      Yes   Return 403  │
                    │               │       ◄─────────┘
                Return 403          │
                                    ▼
                                Process Request
                                    │
                                    ▼
                                Send Response
```

## JWT Token Flow

```
Login Request
    │
    ├─ email + password
    │
    ▼
Find User
    │
    ├─ User not found? ──────► Return 401 Unauthorized
    │
    ▼
Verify Password
    │
    ├─ Password invalid? ────► Return 401 Unauthorized
    │
    ▼
Fetch User Roles
    │
    ▼
Generate Tokens
    │
    ├─ Access Token (24h)
    │  ├─ user_id
    │  ├─ email
    │  ├─ roles
    │  ├─ iat (issued at)
    │  └─ exp (expiration)
    │
    └─ Refresh Token (7d)
       ├─ user_id
       ├─ email
       ├─ roles
       ├─ iat
       └─ exp

    │
    ▼
Return Tokens + User Data
```

## Protected Route Access

```
Request with Token
    │
    ├─ Authorization Header Present?
    │  │
    │  ├─ No ──► Return 401
    │  │
    │  └─ Yes
    │
    ▼
Extract Token from "Bearer {token}"
    │
    ├─ Valid Format? ──► Return 401
    │
    ▼
Verify JWT Signature
    │
    ├─ Invalid Signature? ──► Return 401
    │
    ▼
Check Token Expiration
    │
    ├─ Expired? ──► Return 401
    │
    ▼
Decode Token Payload
    │
    ├─ Invalid Payload? ──► Return 401
    │
    ▼
Store in $request->user
    │
    ▼
Check Role Requirements (if middleware applied)
    │
    ├─ User doesn't have required role? ──► Return 403
    │
    ▼
Proceed to Controller
```

## Password Reset Flow

```
Request Password Reset
    │
    ├─ email
    │
    ▼
Find User
    │
    ├─ User not found? ──► Return 200 (silent, for security)
    │
    ▼
Create Reset Token
    │
    ├─ Generate random 64-char token
    ├─ Hash token (SHA-256)
    ├─ Set expiry (1 hour)
    ├─ Store in password_resets table
    │
    ▼
Queue Email
    │
    ├─ Create reset link with token
    ├─ Queue email job
    │
    ▼
Return 200 OK

─ User Receives Email ─
    │
    ▼
User Clicks Reset Link
    │
    ├─ Navigates to: /reset-password?token={token}
    │
    ▼
User Enters New Password
    │
    ├─ Submits to: POST /api/auth/reset-password
    │  ├─ token
    │  ├─ password
    │
    ▼
Reset Password Endpoint
    │
    ├─ Find Active Token
    │  ├─ Token not found or expired? ──► Return 400
    │
    ▼
─ Validate New Password
    │
    ├─ Valid? ──────► Continue
    ├─ Invalid? ────► Return 422
    │
    ▼
Update User Password
    │
    ├─ Hash new password (Bcrypt)
    ├─ Update password_hash
    │
    ▼
Mark Token as Used
    │
    ├─ Set used_at timestamp
    │
    ▼
Queue Confirmation Email
    │
    ├─ Notify user password changed
    │
    ▼
Return 200 OK
```

## Database Relationships

```
┌─────────────────────┐
│     users           │
├─────────────────────┤
│ id (PK)             │◄─┐
│ email               │  │
│ password_hash       │  │ Many-to-One
│ name                │  │
│ ...                 │  │
│ deleted_at          │  │
└─────────────────────┘  │
         ▲                │
         │                │
    One-to-Many      ┌────┴──────────────┐
         │                                │
         │                                │
         │                       ┌────────┴─────────────┐
         │                       │                      │
┌────────┴──────────┐  ┌────────┴────────┐  ┌─────────┴────────┐
│  user_roles       │  │ password_resets │  │ (Other Models)   │
├───────────────────┤  ├─────────────────┤  │                  │
│ id (PK)           │  │ id (PK)         │  │ customer_id→users│
│ user_id (FK)      │◄─┤ user_id (FK)    │  │ ...              │
│ role_id (FK)      │  │ email           │  └──────────────────┘
│ created_at        │  │ token_hash      │
└───────────────────┘  │ expires_at      │
         │             │ used_at         │
         │             │ created_at      │
         │             └─────────────────┘
    Many-to-One
         │
         ▼
┌─────────────────────┐
│     roles           │
├─────────────────────┤
│ id (PK)             │
│ name                │
│ slug                │
│ description         │
└─────────────────────┘

Default Roles:
- customer  (Regular users)
- employee  (Staff)
- admin     (Full access)
```

## Authentication State Machine

```
START
  │
  ├─► NOT_AUTHENTICATED
  │       │
  │       ├─ Register ──► CREATED_ACCOUNT
  │       │                  │
  │       │                  ▼
  │       │            REGISTERED (auto-login)
  │       │                  │
  │       │                  ▼
  │       └─────────────► AUTHENTICATED
  │       │
  │       └─ Login ─────────────────► AUTHENTICATED
  │
  ▼
AUTHENTICATED
  │
  ├─ Access Protected Routes ───► AUTHORIZED (based on role)
  │                              │
  │                              ├─ Admin Route ──► AUTHORIZED_ADMIN
  │                              ├─ Employee Route ─► AUTHORIZED_EMPLOYEE
  │                              └─ Customer Route ─► AUTHORIZED_CUSTOMER
  │
  ├─ Logout ──► NOT_AUTHENTICATED (clear tokens on client)
  │
  ├─ Token Expires ──► SESSION_EXPIRED
  │                      │
  │                      ├─ Refresh Token ──► AUTHENTICATED (new tokens)
  │                      │
  │                      └─ Re-login ──► AUTHENTICATED
  │
  └─ Change Password ──► AUTHENTICATED (password updated)
```

---

This architecture provides:
- ✅ Clear separation of concerns
- ✅ Secure token-based authentication
- ✅ Role-based access control
- ✅ Scalable middleware pipeline
- ✅ Database-backed role management
- ✅ Secure password reset mechanism
