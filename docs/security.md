# agentMonitor: security notes

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

- Rejects (403) every request whose address is not loopback (`127.0.0.1`, `::1`) or whose `Host` header is foreign, whatever address `php -S` was told to bind to; run it on `127.0.0.1` anyway. Only `GET`, `POST /export` and `POST /judge` are accepted (other methods → 405; cross-origin POST → 403)
- CSP `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'`, `nosniff`, `Referrer-Policy: same-origin`. Inline and eval scripts are allowed because the frontend toolkit runs code from template attributes (`eval` in `SInit.js`, `new Function` in `Hx.js` and `Util.js`), so protection against script injection rests on escaping: every value from a transcript goes through `h()` before it reaches HTML
- Query parameters are validated against allow-lists; none is a filesystem path
- All output is HTML-escaped; CSV cells starting with `= + - @` are prefixed with `'` to prevent formula injection
- The hook only decodes JSON, reads the transcript tail and appends a line; no `exec`/`eval`; it always exits 0 with no output
- The server (not the hook) runs `ps` and `lsof` for the process list in the Agents view (no shell, 4 s limit); it reads only program name, PID, PPID, uptime, CPU, RAM, working directory and listening port, never process arguments. If the commands fail, the view shows "processes unavailable"
- Writes only to `var/`; nothing in `~/.claude`
- Reads Claude Code settings files only for the Approvals tab, and decodes only their `permissions` key
- No network traffic of its own at runtime; libraries are vendored locally. The one exception is the on-demand **session judge** (Assessment tab, `bin/judge.php`): it starts `claude -p` in the background on a dump of the session (commands, paths and your prompts, no file contents) and the Claude Code CLI sends that to the Anthropic API under your own account. Nothing runs it unless you click the button. The judge can read only what is listed under "What the judge can read"; anything it reads is sent to the API.

### What the judge can read

The judge runs `claude -p` with only `Read`, `Grep` and `Glob`, in `dontAsk` mode, no MCP servers. Reads are pre-approved by path rules (`Read(//<dump>/**)` and `Read(//<repo>/prompts/session-review.md)`; in Claude Code rules `//` marks an absolute path). Anything else that would need approval is denied.

- **Restricted:** `Read` of files outside the dump, the instructions file, the judge's working directory (`var/judge/`) and directories added with `--add-dir`.
- **Best effort:** Claude Code applies `Read` path rules to `Grep` and `Glob` on a best-effort basis; it does not match `Grep(path)` or `Glob(path)` rules, so those are not used. A search started from a directory above the allowed ones may still return matches from it.
- **Not restricted:** the working directory `var/judge/` holds the other judgments of this installation, which the judge can read.
- Verified against the Claude Code permissions documentation (path syntax and the `Read`/`Grep`/`Glob` caveat); not verified by running `claude -p` against files outside the allowed paths.

### Requests that change state

Both are `POST`, accepted only from the app's own page (`Sec-Fetch-Site: same-origin`, or an `Origin` of the app's own host), and both write only under `var/`:

- `POST /export` writes a dump of the chosen session (summary, metrics, prompts, events; no file contents) to `var/session_dump/<name>/`
- `POST /judge` starts the session judge: a background `claude -p` run on a dump of the whole session that writes its result to `var/judge/<session>/`. It runs only when you press the button in the Assessment tab; see the network note above

### What the app does not do

- Show or export file contents
- Modify project files or `~/.claude` itself (the judge's own Claude Code run keeps a transcript there, like any session)
- Send anything over the network by itself (only the judge button, described above, starts Claude Code, which does)

### What the app reads

- Claude Code transcripts under `~/.claude/projects/` (read-only)
- The `permissions` key of Claude Code settings files (read-only; `env`, `hooks` and the rest are never decoded)
- Process name, PID, parent PID, uptime, CPU, RAM, working directory and listening ports from `ps` and `lsof` (Agents view; no process arguments)
- Files written by the app itself under `var/`

### Hooks the app installs

None. The optional live-feed hook (`bin/hook.php`, see [Live feed via hook](usage.md#live-feed-via-hook-optional)) is added only by you, by editing a Claude Code settings file. It does not run commands or print output and always exits 0.
