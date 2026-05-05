# Manila Link Up API

Backend API for **Manila Link Up**, a job-matching platform connecting domestic workers (seekers) with employers in Manila, Philippines.

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 (PHP) |
| Primary datastore | Google Cloud Firestore |
| Infrastructure DB | SQLite (queues, cache, sessions, Sanctum) |
| Auth | Firebase Authentication (JWT) |
| Push notifications | Firebase Cloud Messaging (FCM) |
| Firebase SDK | `kreait/laravel-firebase` v7, `google/cloud-firestore` v1.55 |

## Architecture

Laravel acts as a thin API and queue layer — **Firestore is the source of truth** for all business entities (seekers, employers, jobs, applications, chats, ratings, notifications). SQLite holds only Laravel infrastructure tables.

All routes are defined in `routes/api.php`. Business action endpoints use `POST` even for reads (e.g. `/jobs/list`). Admin analytics endpoints use `GET`. All JSON fields use `camelCase`.

## Local Setup

```bash
git clone <repo>
cd manilalinkup-api

composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Add your Firebase service account JSON path to `.env`:

```env
FIREBASE_CREDENTIALS=path/to/serviceAccount.json
```

Start the server:

```bash
php artisan serve
```

> **WSL2 note:** `google/cloud-firestore` v1.55 uses gRPC only. On WSL2, add `zend.max_allowed_stack_size = -1` to `php.ini` to prevent stack overflow. Find it with `php --ini | grep "Loaded Configuration"`.

## Authentication

All endpoints require a Firebase ID token:

```
Authorization: Bearer <firebase_id_token>
```

Admin endpoints additionally require the caller's UID to exist in the `admins` Firestore collection.

## User Roles

- **Seeker** — registers, sets up profile (ID + clearance upload), browses jobs, applies, chats with employers, rates
- **Employer** — registers, sets up profile, posts jobs, manages applicants, hires, rates
- **Admin** — reviews pending verifications, views platform analytics

Seekers and employers must be verified by an admin before they can apply or post jobs.

## Key Features

- Curated job feed based on seeker preferences (tags, salary, location)
- Application lifecycle: Pending → Interview → Hired → Completed
- In-app notifications + FCM push notifications
- Bayesian average ratings for both seekers and employers
- Admin dashboard analytics (overview, funnel, timeseries, user stats, tag stats, rating stats)
- Document verification workflow (ID + police clearance upload)

## Location

Jobs, seekers, and employers are scoped to the 16 official districts of Manila:

`Binondo`, `Ermita`, `Intramuros`, `Malate`, `Paco`, `Pandacan`, `Port Area`, `Quiapo`, `Sampaloc`, `San Andres`, `San Miguel`, `San Nicolas`, `Santa Ana`, `Santa Cruz`, `Santa Mesa`, `Tondo`

## Docs

- [`docs/API.md`](docs/API.md) — full endpoint reference (request/response shapes)
- [`docs/FIRESTORE.md`](docs/FIRESTORE.md) — Firestore collections and field schemas

## Firebase Plan

This app runs on the **free tier (Spark plan)**. Features that require Blaze (Cloud Functions, Cloud Run) are not used.
