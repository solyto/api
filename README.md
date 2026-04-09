<p align="center">
  <img src="https://raw.githubusercontent.com/solyto/assets/main/solyto_logo.png" />
</p>

</p>

solyto is a free, private, all-in-one personal management app — covering your todos, contacts, calendar, notes, news, music and book library in one place, with one login, and one coherent interface. No annoying AI features, no tracking, no subscriptions, no bullshit. Use it on the web, install it as a PWA, or self-host it entirely on your own infrastructure. Built out of frustration with bloated tools, fragmented self-hosted stacks, and services that keep adding things you never asked for.#

# 

# API

Backend for [solyto.app](https://solyto.app).

Built with Laravel 12 and PHP 8.4, also utilizing SabreDAV for DAV synchronization functionality and queued background jobs.

## 

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

## 

## Development

```sh
composer audit    # check for vulnerabilities
php artisan test  # run tests
```

## 

## Deployment

Handled via Ansible in [solyto/deployment](https://codeberg.org/solyto/deployment).

---

## Licensing

Solyto is licensed under the [GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.en.html) (AGPL-3.0).

You are free to use, modify, and self-host this software. If you distribute it or run it as a network service, you must make your source code available under the same license.
