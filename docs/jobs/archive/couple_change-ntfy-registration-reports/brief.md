# Brief: change ntfy registration reports

type: feature
id: couple
branch: feature/couple_change-ntfy-registration-reports
date: 2026-11-08
author: Leander Muskalla

## What

Currently, the API sends a ntfy notification whenever a user registers. However, given the recent amount of notifications, it's clear that's not actually very helpful and it spams my ntfy. Let's remove the "one notification per registration" functionality and replace it with a scheduled daily command that sends a notification like "10 users registered in the last 24 hours". I still get the visibility of knowing what's happening without receiving too many notifications.

## Why

<!-- Why does this need to exist? What problem does it solve for the user? -->

## Out of scope

<!-- What are we explicitly NOT doing in this job? -->

## Notes

<!-- Anything the analyst or developer should know before starting. -->
