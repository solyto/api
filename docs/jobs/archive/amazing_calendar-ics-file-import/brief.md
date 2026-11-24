# Brief: calendar-ics-file-import

type: feature
id: amazing
branch: feature/amazing_calendar-ics-file-import
date: 2026-11-24
author: Leander Muskalla

## What

Users can upload an `.ics` file and import its events into a new calendar or one of their own existing calendars (backlog `calendar-ics-file-import`). API side: `POST /calendars/import/file`, queued import that stores each event raw, progress through `GET /calendars/import/state`. Contract: room decision `01M871VQHC2G6TGT49VB4D037E`.

## Why

Users can export calendars as `.ics` but not import them back; files from other apps can't be brought in without a CalDAV account.

## Out of scope

De-duplication by UID; `.ics` URL subscriptions; importing VTODO; importing into shared or external calendars; "open .ics with solyto" (app backlog).

## Notes

Limits 10 MB / 5 000 objects. One import per user at a time (409), also for the CalDAV import. New stage `failed`. No API lang strings (envelope messages aren't shown to users).
