# Render PHP Backend

This folder is a deployment scaffold for your existing PHP backend from:

`C:\xampp\htdocs\scholar_php`

## Goal

Deploy the current PHP API as a separate Render Web Service, then point the frontend `API_BASE_URL` at that Render backend URL.

## What To Copy

Create a separate backend repository and copy these files from `C:\xampp\htdocs\scholar_php` into that repo root:

- all `*.php` endpoint files
- `backend_common.php`
- your active `uploads/` handling if needed

Then add the files from this folder:

- `Dockerfile`
- `.dockerignore`
- `render.backend.yaml`

## Required Connection Change

Replace the backend repo's `connection.php` with the env-based version from:

- `connection.php.example`

Rename it to:

- `connection.php`

That lets Render inject the database credentials instead of hardcoding local XAMPP values.

## Render Backend Setup

In Render, create a new Web Service from the backend repository.

Use:

- Runtime: `Docker`
- Dockerfile: `./Dockerfile`

Set these environment variables:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`

## Backend URL

After deploy, your backend URL will look like:

`https://your-backend-service.onrender.com`

Because the PHP files are served from the repo root, your login endpoint becomes:

`https://your-backend-service.onrender.com/auth_login.php`

## Frontend Handoff

Once the backend is live, set this in the frontend Render Static Site:

- `API_BASE_URL=https://your-backend-service.onrender.com`

The Flutter app will then call:

`https://your-backend-service.onrender.com/auth_login.php`

## Important Notes

- Your current PHP backend expects MySQL, not Supabase Postgres.
- If you want a real Supabase migration, that is a separate app/data rewrite.
- The `healthCheckPath` in `render.backend.yaml` points to `/auth_login.php`. If you want a cleaner health check, add a tiny `health.php` endpoint later.
