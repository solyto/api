# Brief: add new long-term cache store

status: done
type: feature
id: tunnel
branch: feature/tunnel_add-new-long-term-cache-store
date: 2026-08-25
author: Leander Muskalla

## What

We need a new Redis-based cache store that doesn't get destroyed on deployment.
As far as I see, we currently have 3 cache stores based on Redis: default, cache and session.
We want to split the cache database into two: one ephemeral one and one longterm, so we can decide on deployment to just kill the ephemeral one.

## Why

<!-- Why does this need to exist? What problem does it solve for the user? -->

## Out of scope

<!-- What are we explicitly NOT doing in this job? -->

## Notes

<!-- Anything the analyst or developer should know before starting. -->

