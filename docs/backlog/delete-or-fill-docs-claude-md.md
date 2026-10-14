# Backlog: Delete or fill docs/CLAUDE.md

created: 2026-10-14

## What
`docs/CLAUDE.md` is an unfilled template (`# [Project Name]`, empty Stack/Architecture sections). Delete it, or replace it with real content.

## Why
It is loaded as project context next to the real one (`docs/AGENTS.md`), so agents read placeholder rules such as "NEVER touch [whatever is sensitive in this project]".

## Out of scope
`docs/AGENTS.md` itself.

## Notes
Descoped from the "Standards: mechanical" job (`f3545db`). Deleting is the smaller change unless something loads `docs/CLAUDE.md` by path.
