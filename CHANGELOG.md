# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - Unreleased

First release.

### Added
- Session viewer for Claude Code transcripts: timeline, token and cost accounting, file and command touches.
- Permission analysis: rules from settings files, approval prompts and blocked calls.
- Boundary checks for agent file access.
- Live views: agents, lanes, orbit and 3D office.
- Polish interface translation (`lang/pl.php`), selected with the PL/EN button (cookie `viz-lang`).
- Demo on synthetic sessions (`php bin/demo.php`, `composer demo`).
- Project page for GitHub Pages in `docs/`.
- On-demand session judge (Assessment tab): runs `claude -p` on a session dump with the instructions in `prompts/session-review.md`.

### Notes
- The judge prompt and its Markdown report are in English; the interface is English by default.
