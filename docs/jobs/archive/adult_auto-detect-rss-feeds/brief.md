# Brief: auto-detect rss feeds

type: feature
id: adult
branch: feature/adult_auto-detect-rss-feeds
date: 2026-11-21
author: Leander Muskalla

## What

A user has complained that https://angak.fr/actualites/ doesn't work with feeds. The issue is explainable, the link is not a rss feed.
However, other feed readers have an autodiscover feature. We should implement that as well.
Given the example, the above page has the following meta tags which should be auto-discovered:
<link rel="alternate" type="application/rss+xml" title="Angak &raquo; Flux" href="https://angak.fr/feed/" />
<link rel="alternate" type="application/rss+xml" title="Angak &raquo; Flux des commentaires" href="https://angak.fr/comments/feed/" />
<link rel="alternate" type="application/rss+xml" title="Angak &raquo; Flux pour Actualités" href="https://angak.fr/actualites/feed/" />

## Why

<!-- Why does this need to exist? What problem does it solve for the user? -->

## Out of scope

<!-- What are we explicitly NOT doing in this job? -->

## Notes

<!-- Anything the analyst or developer should know before starting. -->
