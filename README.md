# agentMonitor

A local web app for inspecting what [Claude Code](https://claude.com/claude-code) agents did in your projects: **which files each agent read, searched and changed, in what order, and which agent did it** (main session or subagent).

It reads Claude Code transcripts (and, optionally, a hook feed), never modifies anything in your projects or in `~/.claude`, and makes no network requests.

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Session view](#session-view)
  - [Overview](#overview)
  - [All sessions](#all-sessions)
  - [Office](#office)
  - [Agent boundary](#agent-boundary)
  - [Blocked calls](#blocked-calls)
  - [File origin](#file-origin)
- [Export and analysis](#export-and-analysis)
- [Live feed via hook (optional)](#live-feed-via-hook-optional)
- [Verification against the OS](#verification-against-the-os)
- [Testing](#testing)
- [Maintenance](#maintenance)
- [Security](#security)
- [Third-party libraries](#third-party-libraries)
- [Limitations](#limitations)
- [Project documentation](#project-documentation)

## Features

- **Files read / changed** – per-file counts, first and last touch, which agent, partial reads, edits without a prior read
- **Commands** – every Bash call in order, or grouped by program; Bash is parsed (quotes, heredocs, redirects, `$(…)`, variables, loops, `cd`) to recover the files each command touches
- **Messages** – prompts, tasks handed to subagents, subagent replies, `SendMessage`, cross-session messages
- **Timeline, Graph, Log** – per-agent tracks and a files × time raster, an Obsidian-style agent ↔ file graph, and a flat call log
- **Cost** – token usage priced at API list prices, broken down per turn, agent, call and tool result (a comparison metric, not an invoice)
- **Overview** – a start page that flags context-hygiene, communication and boundary problems across your sessions
- **Agent boundary** – declare where agents may work and see every call that left it
- **All sessions** – the same tables across every session, including ones whose transcripts Claude Code has already deleted
- **Office** – a live, Minecraft-style 3D view of currently active agents
- **Export** – session dump (Markdown, JSON, CSV, ZIP) and a skill that evaluates it
- **Live refresh** – the UI polls every second and re-renders only when data changed, so scroll position and text selection stay intact

## Requirements

- PHP 8.4 (CLI; no framework, no Composer)
- macOS or Linux
- Node.js – optional, only for the refresh test
- Claude Code (transcript format verified on 2.1.300)

## Quick start

```bash
git clone <repository-url> agentMonitor
cd agentMonitor
cp .env.example .env            # optional, see Configuration
php -S 127.0.0.1:8090 -t public public/index.php
```

Open <http://127.0.0.1:8090/>.

Always bind to `127.0.0.1` (see [Security](#security)).

## Configuration

Configuration lives in `.env` (git-ignored, because it contains local paths). A variable set in the process environment takes precedence over the file.

| Variable | Description |
| --- | --- |
| `AGENT_ALLOWED_RW` | Comma-separated paths where agents may read and write. `~` is expanded; relative entries are ignored; matching is by path prefix. |
| `AGENT_ALLOWED_R` | Comma-separated paths where agents may only read. A write there is a violation. |

Example (`.env.example`):

```dotenv
AGENT_ALLOWED_RW=~/projects,~/.claude/projects,~/.claude/plans,/private/tmp/claude-1000
AGENT_ALLOWED_R=~/.claude/skills,~/.claude/plugins
```

In the last RW path (the Claude Code scratchpad) replace `1000` with your own UID (`id -u`). Without these entries the boundary feature is disabled.

## Usage

### Session view

- **Left panel** – recent Claude Code sessions (all projects), a project selector, a "follow latest" mode, and a theme toggle (dark by default).
- **Header** – title, directory, source (transcript / hook), question tabs with counts, and one shared filter row.
- **Detail panel** (right, hidden by default) – opens when you click a file or call; close with × or Esc.

Question tabs:

| Tab | Answers |
| --- | --- |
| **Files read** | Which files reached the agent: reads, "injected" (by Claude Code), "fragment only", "modified", agents, first/last read. Click a column header to sort; "+ searched / listed / run" adds files that were only searched, listed or executed. |
| **Files changed** | Edited and written files, with "not read before" (edit without a prior read) and the agents that changed them. |
| **Commands** | Bash calls in order (✓ executed, ✗ non-zero exit, ⊘ blocked/rejected) or "by program" (usage count, role, subcommands). |
| **Messages** | Command flow: user prompts, subagent tasks (full prompt), subagent replies (truncated to 600 characters, because they usually quote files), `SendMessage`, cross-session messages. |

Additional views: **Timeline**, **Graph**, **Log**, **Boundary**.

Filters (combinable, apply to every view and to the export):

- **agent**, **turn** (everything after a prompt until the next one; also by clicking a prompt number on the timeline)
- **origin** – all / agent tools only / injected context only
- **project only** – skip files outside the session directory
- **status** – `executed` (including Bash with exit code ≠ 0), `blocked`, `failed`
- **boundary** – `outside` / `suspicious` / `stopped`

The ⚠ badge marks errors, blind spots, incomplete parses, repeated reads, reads of files already in context and edits without a prior read. Gaps longer than 5 minutes are compressed to a ⋯ marker.

**Timeline** – agent tracks with call bars (colour = operation) and subagent start arcs, numbered prompts, background-task notifications, compactions, a context-size chart, and a file × time raster (rows: files, directories or commands).

**Graph** – agents are large nodes; files are coloured by origin and a green ring marks a change. Files cluster in labelled folder regions (the 12 largest folders are always labelled, the rest from zoom-in). Click a file for its path, operations, agents and a link to its calls.

**Log** – all calls, newest first, with touched files and error / blind-spot / problem badges.

**Text interface:**

```bash
php bin/dump.php <session_id> [--agent=main|<agentId>] [--blind]
```

### Overview

`/` without a selected session. Three axes, each with a status of *ok*, *needs attention* or *check now*:

- **Context** – cold cache after a > 5 min pause, a quick question in a large context, returning to a stale session, huge context (> 400k tokens), expensive tool results (> $0.50), sessions with > 3% tool errors
- **Communication** – user interruptions, subagent reports blocked by a hook, failed subagents
- **Boundaries** – hook-blocked calls on protected paths, auto-mode blocks, and boundary violations from `.env`

Each axis separates **What you can change** from **What the agent can change**. Every signal kind shows a count, a dollar sum (within the kind only, since kinds overlap), a "why" and a "what to do"; expanding it lists cases, each linking to the session with the right view preselected.

Below the axes: usage limits and API errors, the most expensive turns (> $5), and sessions with their three most frequent signal kinds. Period: 24 h / 7 days (default) / 30 days / all; project filter.

### All sessions

"Σ All sessions" at the top of the left panel. Tabs: Files read, Files changed, Commands (by program), Blocked, Boundary – the same tables as in a session, computed from aggregates in `var/summary/`.

- **Period:** all / 30 / 14 / 7 days / **1 h / 5 min live**
- **Project** filter, with "project only" for the file tabs
- **Search** by path (press Enter; filtering runs locally in PHP over the whole list)
- **Exclude** (comma-separated): an entry starting with `/` or `~` is a path prefix, anything else is a substring
- **⇩ CSV** – the entire filtered list of the current tab, including rows not shown
- Click a file or program to see which sessions touched it, with links
- First 300 rows shown; "show all" renders everything

Messages, Timeline, Graph, Log and Commands "in order" need individual calls and are available for a single session only.

### Office

`/office` – a global view (not a session tab). A three.js scene shows what active agents across all sessions are doing right now, built from transcripts only.

- **Who is present:** only active agents. A main session disappears after 10 min of silence (30 min during a running tool call) and stays while any of its subagents works; a subagent disappears right after it finishes.
- **Layout:** one desk row per project, a coloured marker above each character per session. Legends "Projects" and "Status" on the left (click = open session).
- **Appearance:** head = model class (Opus, Sonnet, Haiku; any other model gets the default head); shirt and row carpet = project colour (derived from the directory name, fixed 11-colour palette, collisions possible with many projects).
- **States:** `working`, `delegated` (waiting for subagents), `idle` (waiting for you, raised hand), `stuck` (limit or API error). A transcript cannot distinguish "waiting for permission" from "long command"; both show as `working`.
- **Boundary:** a blocked attempt to leave the boundary = the character climbs the wall and falls; an executed one = digging on the grass.
- **Cost:** a coin per $0.01 spent since the last refresh; from $0.50 in the last 5 min the label shows the amount.
- **Messages:** an envelope flies between agents (task, result, `SendMessage`, cross-session message).
- **Controls:** left button rotates, right button / Shift pans, wheel zooms; auto-refresh every second (paused in a hidden tab).

Verified against a transcript simulator; not verified on real sessions.

### Agent boundary

Set `AGENT_ALLOWED_RW` / `AGENT_ALLOWED_R` in `.env`. Each call that left the boundary is classified:

- **outside** – executed and touched a path outside the lists (or wrote to a read-only zone): your safeguards let it through
- **suspicious** – a Bash call executed but its paths cannot be determined (glob, variable, heredoc)
- **stopped** – an attempt to leave the boundary that a hook, rule, auto mode or the sandbox stopped (recognised by "Operation not permitted")

Visible on Overview (Boundaries axis), in the **Boundary** tab (session and all sessions, paths grouped, CSV), in the `boundary` filter, and as a marker next to a call in Commands.

### Blocked calls

The `blocked` status shows calls stopped by a hook, a `permissions.deny` rule, auto mode, the built-in safety check, or your own rejection. Data comes from decisions Claude Code records in the transcript, so new rules appear on their own. Files listed under `blocked` are the ones the agent *tried* to reach. All sessions → **Blocked** groups them by mechanism and rule (CSV available).

### File origin

The origin filter separates:

- **automatic context** (purple) – `CLAUDE.md`, rules and other files injected by Claude Code
- **read by the agent** (orange) – `Read`, `cat`, `sed`, …

Context is visible only when the source is a transcript.

## Export and analysis

The "⇩ export session" button (or `php bin/export.php <session_id> [--agent=…]`) writes `var/session_dump/<date>-<session>/` with the same source and agent filter as on screen:

| File | Content |
| --- | --- |
| `summary.md` | Text summary |
| `metrics.json` | Time, errors, repeated reads, prompt turns, agents, tokens and cost |
| `prompts.json` | Your messages, commands, notifications |
| `events.jsonl` | One call per line with resolved paths |
| `files.csv`, `agents.json`, `manifest.json` | Supporting data |

`--zip=file.zip` writes the same as a ZIP archive.

To evaluate a session, run the bundled skill from the project directory:

```text
/analiza-sesji var/session_dump/<name>
```

It writes `analiza.md` into the dump folder: what went well, what went badly and what to improve, with evidence from the metrics for every point.

A dump contains agent commands and your prompts (no file contents), so treat it like terminal history. `var/` is git-ignored.

## Live feed via hook (optional)

Without a hook the app already shows live sessions from transcripts. A hook adds data in a documented format and paths frozen at event time. It is **not installed by default**.

Add to a Claude Code settings file (for example `~/.claude/settings.json`), using the real path to this directory:

```json
"hooks": {
  "PostToolUse": [{"matcher": ".*", "hooks": [{"type": "command", "command": "php /path/to/agentMonitor/bin/hook.php"}]}],
  "PostToolUseFailure": [{"matcher": ".*", "hooks": [{"type": "command", "command": "php /path/to/agentMonitor/bin/hook.php"}]}]
}
```

- Applies to every Claude Code session covered by that settings file
- Adds about 33 ms (median) per tool call
- To disable, remove the entry

## Verification against the OS

To check that the app shows the truth, compare it with a system-level file access log. This runs with root privileges and records every path read under the directory; use it only for the duration of the test.

1. In a separate terminal:
   - macOS:
     ```bash
     sudo fs_usage -w -f filesys | grep --line-buffered '<project dir>' > /tmp/fs.log
     ```
   - Linux:
     ```bash
     strace -f -tt -e trace=openat -p <claude pid> -o /tmp/fs.log
     ```
2. Give the agent a task in that project. Keep one agent working at a time, because the log cannot tell agents apart.
3. Compare:
   ```bash
   php bin/verify.php /tmp/fs.log <session_id> --root=<project dir>
   ```
   The report splits files into three groups: matching / only in the app / only in the log (grouped by process).
4. Delete `/tmp/fs.log`.

The log parser has only been tested on synthetic lines; the first run on a real `fs_usage` log may need a parser fix.

## Testing

```bash
php tests/run.php            # Bash parser, structured tools, model, signals, boundary, office
php tests/smoke.php          # server on an app copy with a fake HOME: every route returns 200, no PHP warnings
node tests/refresh.test.js   # view refresh behaviour (virtual clock); Node only, no packages
```

`php tests/run.php` also runs the refresh test when `node` is on `PATH` (otherwise it prints SKIP). Every parser fix ships with a case in `tests/run.php`.

## Maintenance

**Reset to post-install state:**

```bash
php bin/reset.php          # preview: what would be removed and how many sessions exist only in the archive
php bin/reset.php --yes    # delete
```

This clears `var/`: aggregates (`var/summary/`), hook feed (`var/events/`), session dumps and the hook error log. It never touches Claude Code transcripts (`~/.claude`) or `.env`. **Sessions whose transcripts Claude Code has already deleted are lost for good**; the preview shows how many.

**Do not delete `var/summary/`.** Claude Code removes transcripts after `cleanupPeriodDays` (14 days by default) while the aggregates stay, so `var/summary/` is the only copy of older history. Only sessions whose transcript changed are recomputed.

## Security

Transcript and hook data are **untrusted**: commands and paths may have been influenced by prompt injection.

### What the app exposes

The app shows **commands run by agents and file paths**. A command can contain a password or token (`mysql -p…`, `curl -H "Authorization: …"`). Treat the UI, exports and `var/` like your terminal history.

### Rules for users

- **Do not expose the server.** Run it only on `127.0.0.1`; never on `0.0.0.0`, through a tunnel (ngrok etc.) or port forwarding. The app rejects requests with a foreign `Host` header, but that does not replace this rule.
- **Do not copy `var/` or session dumps to places others can access** (git, shared folders, cloud, ticket attachments).
- **Do not change the permissions of `var/`** (`0700`, files `0600`); they match those of Claude Code transcripts.
- **OS verification runs as root.** Run it only for the duration of the test and delete the log.
- **Cleanup:** `var/events/` can be removed at any time; Claude Code transcripts are unaffected.

### Implementation guarantees

- Binds to `127.0.0.1` only; only `GET` and `POST /export` are accepted (other methods → 405; cross-origin POST → 403)
- CSP `default-src 'self'`, `nosniff`, `Referrer-Policy: same-origin`
- Query parameters are validated against allow-lists; none is a filesystem path
- All output is HTML-escaped; CSV cells starting with `= + - @` are prefixed with `'` to prevent formula injection
- The hook only decodes JSON, reads the transcript tail and appends a line; no `exec`/`eval`; it always exits 0 with no output
- Writes only to `var/`; nothing in `~/.claude`
- No network traffic at runtime; libraries are vendored locally

### What the app does not do

- Show or export file contents
- Modify project files or `~/.claude`
- Send anything over the network

## Third-party libraries

Vendored (the app never contacts a CDN), each with its MIT licence next to the files:

- `public/assets/vendor/force-graph-1.51.4.min.js` – force-graph 1.51.4 (graph view)
- `public/assets/vendor/three-0.183.2/` – three.js 0.183.2 (Office)
- `public/assets/toolkit/` – small frontend toolkits (`Util`, `Hx`, `SInit`, `Filter`)

## Limitations

- Files read indirectly by programs (`php bin/console`, `composer`, `python3 - <<EOF`) are only flagged as "indirect"
- In hook mode, sessions started without the `claude` wrapper are visible through transcripts only
- The transcript format is internal and undocumented; the UI warns when a large file yields zero `tool_use`
- Cost is estimated from list prices (`src/Pricing.php`); a model without an entry shows `?`
- Office and the OS-log parser are not verified on real data (see above)

## Project documentation

- [`CLAUDE.md`](CLAUDE.md) – architecture, data model, parser rules and security requirements (in Polish)
- [`milestones.llm.md`](milestones.llm.md) – plan and status
- [`.env.example`](.env.example) – configuration template

The UI and everything the app generates is in English; data (session titles, prompts, paths, commands) is shown as-is.

## License

No license file is included yet. Add one (for example MIT) before publishing the repository.
