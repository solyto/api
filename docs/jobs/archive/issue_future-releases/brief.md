# Brief: future releases

status: done
type: feature
id: issue
branch: feature/issue_future-releases
date: 2026-08-25
author: Leander Muskalla

## What

Recently, I've encountered an issue with a musical release. I'm getting notifications for the same release each and every day.
The reason is most certainly that the release date for some reason is Mid September, in one month.
So possible, the API we're grabbing releases from, has that entry with that release date.
We don't check it. We just keep sending notifications.
Please check all Release Notification implementations (e.g. GrabMusicReleases). Personally, I would suggest to disregard future releases entirely.
I'm not helped at all knowing that there WILL be a release. I want to be notified about "New Releases".

## Why

<!-- Why does this need to exist? What problem does it solve for the user? -->

## Out of scope

<!-- What are we explicitly NOT doing in this job? -->

## Notes

<!-- Anything the analyst or developer should know before starting. -->

