# Backlog: Typed returns instead of mixed

created: 2026-10-14

## What
Give a concrete return type to the remaining `: mixed` methods where one exists: `IntegrationGateway::commit()` (`app/Shared/Services/IntegrationGateway.php:32`, delegates to `QuickAddService::commit`, which returns `?Model`), `DiscogsService::getRelease()` (`:91`), `PasskeyService::cborReadValue()` (`:304`) (rule 4.6).

## Why
`mixed` hides the contract from callers and from PHPStan. The `Library*Service` and `QuickAddService` ones were fixed in `ca1627b`; these were listed as follow-ups.

## Out of scope
`UserCacheService::get/remember` and `BaseNotification::withLocale`: they return whatever the caller stored or the callback returns, so `mixed` is correct there.

## Notes
`cborReadValue` decodes CBOR and may honestly be a union; keep `mixed` if a union isn't clearer.
