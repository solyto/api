# Backlog: Telegram bot command dispatch by method name

created: 2026-10-14

## What
`app/Bots/Traits/IsTelegramBot.php:146` dispatches bot commands with `$this->{$method}()`, where `$method` is built from user input (`/start` → `startCommand`). Replace it with a `match` or an explicit command map (rule 4.3).

## Why
Any message that maps to an existing `*Command` method calls it. Rule 4.3's check matches this line. The `routes[]` dispatch next to it was fixed in `ca1627b`; this one was left as a follow-up.

## Out of scope
The `exit(1)` calls in `SolytoBot.php:259,265` (code-quality report §3.7); a separate entry if wanted.

## Notes
Keep the "unknown command → continue" behaviour.
