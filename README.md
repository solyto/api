# solyto api

Backend for [solyto.app](https://solyto.app) — a productivity hub that brings tasks, notes, calendars, and personal organization tools into one place.

Built with Laravel 12 and PHP 8.4.

## Features

Calendars, contacts, todos, notes, time tracking, finances, feeds, weather, shortcuts, clipboard, libraries, statistics, Telegram bot integrations, and CalDAV/CardDAV support via Sabre DAV.

## Requirements

- PHP 8.4
- MariaDB
- PostgreSQL (for DAV)
- Redis

The easiest way to get everything running locally is via the [solyto workspace](https://codeberg.org/solyto/solyto), which sets up all services with Docker Compose.

## Setup

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## Development

```sh
composer audit    # check for vulnerabilities
php artisan test  # run tests
```

## Deployment

Handled via Ansible in [solyto/deployment](https://codeberg.org/solyto/deployment).
