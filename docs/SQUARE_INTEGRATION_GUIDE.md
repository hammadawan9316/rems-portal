# Square Integration Guide

This guide explains how to run the Remote Estimation project intake flow with Square.

## Workflow Implemented

1. Client submits project form with optional files.
2. Project is saved locally first in the projects table.
3. Customer is searched in Square by email.
4. If customer does not exist, a new customer is created.
5. Draft estimate is created after project creation.
6. Owner notification email is queued.

## Files Added For This Flow

- app/Config/Square.php
- app/Libraries/SquareService.php
- app/Controllers/api/ProjectIntakeController.php
- app/Models/ProjectModel.php
- app/Database/Migrations/2026-04-12-000002_CreateProjectsTable.php
- app/Views/emails/remote_estimation.php
- app/Helpers/queue_helper.php

## Environment Setup

Set these values in .env:

- square.baseUrl
- square.apiVersion
- square.accessToken
- square.locationId
- square.currency
- square.ownerNotificationEmail

Recommended values:

- square.baseUrl = https://connect.squareup.com for production
- square.baseUrl = https://connect.squareupsandbox.com for sandbox
- square.currency = USD

## API Endpoint

POST /api/projects/submit

### Required Fields

- client_name
- client_email
- project_title

### Optional Fields

- project_description
- client_phone
- estimated_amount (in cents)
- file uploads as form-data file fields

## Migration

Run:

php spark migrate

## Test Request Example

Use multipart form-data with fields and file inputs.

## Queue Worker

Project submission queues owner notification email. Run worker:

php spark queue:emails --limit 20

## Important Notes

- The estimate is created after project creation.
- If Square credentials are missing, submission still succeeds locally and Square is skipped.
- Failures from Square are stored in projects.square_error.

## Package Compatibility Status

- codeigniter4/queue: Not installable on current PHP 8.1 (requires PHP 8.2).
- square/square: Installable and already installed in this project.

Installed command used:

composer require square/square

Current implementation uses direct Square REST API calls through CodeIgniter curlrequest. You may keep this approach or migrate SquareService to use the installed SDK later.
