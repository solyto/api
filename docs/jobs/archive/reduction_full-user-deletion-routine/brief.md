# Brief: full user deletion routine

type: feature
id: reduction
branch: feature/reduction_full-user-deletion-routine
date: 2026-10-13
author: Leander Muskalla

## What

The first solyto user has requested his account to be deleted.
As far as I'am aware, we have no full user deletion routine that actually removes everything user data of that user from the server.
We should though! Probably inside the UserService.
Then also create a artisan cli command I can call to remove a specific user (by email address) with confirmation before deletion.

## Why

<!-- Why does this need to exist? What problem does it solve for the user? -->

## Out of scope

<!-- What are we explicitly NOT doing in this job? -->

## Notes

<!-- Anything the analyst or developer should know before starting. -->
