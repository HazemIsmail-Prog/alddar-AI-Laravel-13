# HVAC Field Service — Laravel API

Laravel 13 API (Sanctum SPA cookie auth). Default local database is SQLite.

## Setup

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

API: `http://localhost:8000`

## Seed logins

Password for all users: `password`

| Email | Role |
|---|---|
| admin@example.test | Admin |
| callcenter@example.test | Call center |
| dispatcher@example.test | Dispatcher |
| tech@example.test | Technician |
| tech2@example.test | Technician |
| accountant@example.test | Accountant |

## CORS / Sanctum

`.env` should include:

```
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:8000
SESSION_DOMAIN=localhost
```

MySQL is optional; set `DB_CONNECTION=mysql` and related vars if you prefer it over SQLite.
