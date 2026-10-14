# Brief: backwards compatibility with frontend

type: report
id: variety
branch: report/variety_backwards-compatibility-with-frontend
date: 2026-10-14
author: Leander Muskalla

## What

Do full analysis: how can we solve backwards compatibility of the API layer with the frontend app.
Until now we've always deployed a new verison to both at once. We will still do that but since the release to f-droid, we have to assume that users did not update their app and have older versions of the frontend than the api.
We need a strategy for this, so we can still keep the web-version uptodate all the time whilst keeping old frontend versions that run on mobile or desktop functional without weird bugs or unintended side effects.
Develop a new deployment strategy for this.
Write it to docs/reports/ as a report.

## Why

<!-- Why does this need to exist? What problem does it solve for the user? -->

## Out of scope

<!-- What are we explicitly NOT doing in this job? -->

## Notes

<!-- Anything the analyst or developer should know before starting. -->
