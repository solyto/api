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

The easiest way to get everything running locally is via the [solyto workspace](https://github.com/solyto/solyto), which sets up all services with Docker Compose.

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

Contributing code? Read the coding standards first: [docs/STANDARDS.md](docs/STANDARDS.md) — numbered, checkable rules for controllers, OpenAPI annotations, requests, services, models, resources, errors, caching, jobs and formatting, each with examples from this repository — and [docs/TESTING.md](docs/TESTING.md) for how tests are written here.

## 

## MCP server (experimental, local only)

An MCP server lets a local AI agent use the API as one user. It has two tools: `list_endpoints` lists the `/api/v1` endpoints and `call_api` calls one with that user's token. It is off by default.

```sh
# .env
MCP_ENABLED=true

php artisan app:mcp:token you@example.com   # prints a non-expiring token once
claude mcp add --transport http solyto http://localhost:8000/mcp --header "Authorization: Bearer <token>"
php artisan mcp:inspector mcp               # debug the server in the MCP inspector
```

`call_api` refuses the endpoints that would sign the user out or revoke the token (`auth/*` except listing tokens and managing passkeys, and `users/change-password`). To revoke the MCP token, use `auth/revoke-token` from another session, or delete the `mcp-token` row.

## 

## Deployment

Handled via Ansible in [solyto/deployment](https://github.com/solyto/deployment).

---

## Licensing

Solyto is licensed under the [GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.en.html) (AGPL-3.0).

You are free to use, modify, and self-host this software. If you distribute it or run it as a network service, you must make your source code available under the same license.
