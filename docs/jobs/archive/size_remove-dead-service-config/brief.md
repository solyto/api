# Brief: Remove dead service config

type: feature
id: size
branch: feature/size_remove-dead-service-config
date: 2026-10-14
author: Leander Muskalla

## What
Delete two leftover blocks from `config/services.php` that no feature uses:

1. **`bremerstadtreinigung`** (`config/services.php:34`): Bremen trash-pickup ICS URL template.
2. **`radicale`** (`config/services.php:52`, `RADICALE_URL`). The built-in SabreDAV server replaced it.

## Why
Both come from the initial commit and have never been used by app code since (`git log -S` shows only `Initial Commit`). Dead config makes it look as if solyto depends on these services. That gets in the way of sorting external services into integrations (instance-wide) and plugins (per user). The owner confirmed on 2026-10-14 that both are old and can go.

## Out of scope
- Any other `config/services.php` entries, and the integrations/plugins work itself.
- `AiUsageFeatureEnum::ASSISTANT_CHAT`. It goes away with the removal of the whole `ai_usage` setup (model, table, enum, `saveUsageForUser`, `ai_tokens` in the statistics overview), which is part of the AI plugin work.

## Notes
Before deleting, grep the whole repo once more (including `.env.example`, Docker/compose files and `resources/`) for `bremerstadtreinigung`, `trash_calendar_url`, `radicale` and `RADICALE_URL`. If anything outside `config/services.php` references them, stop and report it.

Check: `composer test` and `composer analyse` exit 0.
