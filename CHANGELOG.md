# Changelog

All notable changes to Alfred are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). While the version is 0.x, minor
releases (0.2 → 0.3) may contain breaking changes; patch releases never do.

## [0.9.2] - 2026-10-23

### Added
- Laravel 13 support.

### Changed
- README: installation now starts by adding the GitHub repository to
  Composer (Alfred is not on Packagist), with the token step for private
  repositories. The upgrade commands no longer show an old version.
- Fuller package description, keywords, homepage and support links;
  `illuminate/console` is now a declared dependency.

## [0.9.1] - 2026-10-23

### Changed
- **Redesigned buttons and actions.** Every screen has one clear main
  action; secondary actions are quieter and destructive ones are red. Big
  self-explaining tiles for the first choice (explain, change, report) and
  for "Send for review" (pull request, ticket). Icons, loading spinners in
  the button you clicked, focus rings for keyboard use, and form buttons that
  stay visible at the bottom of the panel. The preview bar is now a dark bar
  with "Open pull request" as its main action. A created ticket's text folds
  away instead of looking editable.

## [0.9.0] - 2026-10-23

### Added
- **Pull requests.** "Open pull request" on any change with code, and
  "Also open a pull request" when creating its ticket. Alfred reads the
  changed files from the base branch through the provider's API,
  re-applies the edits, commits them as one commit on a new branch and opens
  a draft pull request with the request, files, page, screenshots and
  ticket. Nothing is written on the server, so it works in propose mode on
  staging. Providers: GitHub, GitLab (merge requests) and Bitbucket Cloud,
  chosen with `ALFRED_PULL_REQUESTS`; add your own via
  `pull_requests.custom`. Only `editAlfred` users can open them, once per
  change.
- The preview bar has **Open pull request** and **Prepare ticket**. Both
  first save a screenshot of the previewed page as the "current view", so
  the pull request and ticket show how the change looks.
- With a ticket, the pull request title and branch carry the ticket key
  (so Jira and Linear link them), and a GitHub or GitLab issue is closed by
  its pull request.
- Settings: `ALFRED_PR_BASE`, `ALFRED_PR_DRAFT`, `ALFRED_PR_BRANCH_PREFIX`,
  `ALFRED_PR_PATH_PREFIX`, `ALFRED_PR_LABELS`, `ALFRED_BITBUCKET_*`.

### Fixed
- The preview bar no longer sits under the open panel.
- Screenshot thumbnails show the whole picture instead of a crop.
- An edit written with spaces is now placed correctly in a file indented with
  tabs (and the other way round). Before, the replacement could end up with
  the wrong indentation.

### Upgrade
- Run `php artisan migrate` (pull request columns on `alfred_changes`).

## [0.8.0] - 2026-10-23

### Added
- **Tickets in more trackers.** Besides Jira, Alfred can now create tickets
  in GitHub Issues, GitLab, Linear, Trello, ClickUp and Asana. Choose them
  with `ALFRED_TICKETS` (for example `ALFRED_TICKETS=jira` or
  `ALFRED_TICKETS=github,trello`). With several, the first is the default
  and people pick one in a "Send to" list. Only trackers whose settings are
  complete are offered.
- Screenshots are attached (Jira, Trello, ClickUp, Asana), uploaded and shown
  in the description (GitLab, Linear), or linked (GitHub, whose API can't
  take uploads). A failed upload is a warning, never a failed ticket.
- Your own trackers: implement `Alfred\Tickets\Trackers\Tracker` and list it
  in `tickets.custom`.
- `ALFRED_TICKET_LABELS` and `ALFRED_TICKET_INCLUDE_DIFF` apply to every
  tracker.

### Changed
- "Prepare Jira ticket" is now "Prepare ticket". The change API returns
  `ticket: {provider, label, key, url}` instead of `jira_key`/`jira_url`.
- Jira settings moved to `trackers.jira` in the config file. The env
  variables are unchanged, and `ALFRED_JIRA=true` still works when
  `ALFRED_TICKETS` is not set.

### Upgrade
- Run `php artisan migrate`: tickets are now stored with their tracker.
  Existing Jira tickets are carried over.
- Replace `ALFRED_JIRA=true` with `ALFRED_TICKETS=jira` (optional, the old
  setting keeps working). `ALFRED_JIRA_LABELS` no longer defaults to
  `alfred`; the shared `ALFRED_TICKET_LABELS` does.

## [0.7.0] - 2026-10-23

### Changed
- **Breaking: Alfred is now hidden from everyone by default.** Before, every
  signed-in user saw it. Now only users whose email is in the new
  `ALFRED_USERS` list, or in `ALFRED_VERIFIERS`, see it, unless you define
  your own `useAlfred` gate. Everyone else gets no button, no script and no
  recording, and Alfred's endpoints answer 403.
- `ALFRED_USERS` and `ALFRED_VERIFIERS` accept patterns such as
  `*@acme.com` as well as exact emails, case-insensitive.

### Upgrade
- Add `ALFRED_USERS` (for example `ALFRED_USERS=*@your-company.com`) if
  people other than your verifiers should keep seeing Alfred. If you
  already define `useAlfred` yourself, nothing changes for you.

## [0.6.0] - 2026-10-23

### Added
- **Your own name, button and colour.** `ALFRED_NAME` replaces "Alfred" in
  the panel, messages and tickets. `ALFRED_ICON` sets the button: an image
  (a path in `public/`, a URL or a `data:` URI) or up to 3 characters such
  as an emoji. `ALFRED_COLOR` sets the accent colour; only plain CSS colour
  values are accepted. A button image that fails to load falls back to "?".

## [0.5.0] - 2026-10-23

### Added
- **Report or request.** A third choice after clicking an element. The
  person types what's wrong or what they need; Alfred takes a screenshot of
  the element and builds a ticket with their words and a "Where in the
  code" section (page URL, route and controller, component and file, views,
  and the lines that load the data). No AI call. `ALFRED_REPORT` (default
  on) and the new `reportAlfred` gate (default: everyone who can use
  Alfred). Reporters only see their own requests.
- **For developers tab** on explanations: entry point, flow with file:line,
  queries, caching and edge cases. Controlled by the new `viewAlfredCode`
  gate (default: verifiers).
- Explanations now include a worked example and a glossary of the terms
  shown on screen.

### Changed
- The main explanation is written for non-technical readers: business
  logic only. If it contains class names, methods, files, tables or SQL, it
  is sent back to the AI to be rewritten.
- Unverified explanations made by earlier versions are regenerated in the
  new format when next opened. Verified ones are kept.
- The **?** button moves beside the panel while the panel is open.

### Upgrade
- Run `php artisan migrate` (new columns on `alfred_explanations` and
  `alfred_changes`).

## [0.4.2] - 2026-10-22

### Fixed
- Screenshots failed on pages using Alpine.js, Livewire or Vue (so every
  Filament page): attributes such as `x-on:click`, `@click`, `:class` and
  `wire:key` are valid HTML but invalid XML, and the screenshot is rendered
  as an SVG image, which must be valid XML. They are now stripped from the
  screenshot's copy of the page (never from the live page).

## [0.4.1] - 2026-10-22

### Changed
- README rewritten for a general audience: what Alfred does, a quick start,
  each feature, then security, permissions and a configuration reference.
- Examples in the agent's prompts and tool descriptions are now neutral
  (orders and visitors) instead of coming from one specific app.

## [0.4.0] - 2026-10-22

### Added
- **Preview on this page.** The person who asked for a change can see it
  rendered for real, only in their own session, without anything being
  written to the codebase. Made for staging/propose mode: a manager asks
  for a change, previews it, then turns it into a Jira ticket. Changed PHP
  classes load from a private temp copy for that user's requests; changed
  Blade views render from it. A "Previewing … · Stop preview" bar shows on
  every page while it's on. `ALFRED_EDIT_PREVIEW` (default on when
  `ALFRED_EDIT` is on); automatically off on Octane. Files edited since the
  proposal, already-loaded classes and CSS/JS needing a build are reported
  instead of faked.

### Fixed
- Screenshots failed on pages with an image from a site that doesn't allow
  it to be copied (CORS) — e.g. Filament's default avatars from
  ui-avatars.com, which sends a broken `Access-Control-Allow-Origin` header.
  Such images are now detected first and left out of the screenshot (logged
  as `[Alfred] leaving an image out …`), with a transparent placeholder as a
  second safety net.
- Screenshot failures were silent. They are now logged to the browser
  console (`[Alfred] …`) and shown with the reason in the ticket screen.

## [0.3.1] - 2026-10-22

### Fixed
- **Edits failing on correct ideas.** Edit placement is now forgiving: when
  the model's search text differs only in indentation, surrounding
  whitespace, CRLF/LF or copied line-number prefixes, Alfred finds the one
  place it belongs, uses the file's real text and re-indents the
  replacement. Ambiguous matches name the line numbers; misses show the
  closest lines in the file so the next attempt can copy them.

### Added
- `ALFRED_EDIT_REASONING_EFFORT` (default `medium`) — edits get more
  reasoning than explanations (applies to reasoning models only).
- Agent trace on every change ("What Alfred tried": each tool call and its
  result), stored in `alfred_changes.trace` — new migration.
- Runs that never produce a valid edit are kept as `failed` changes with
  their error and trace, instead of disappearing.

### Changed
- `LlmClient` gained `withReasoningEffort()`; `Agent` gained `llm()` and
  `using()`. Custom LLM clients must implement the new method.

## [0.3.0] - 2026-10-22

### Added
- **Jira tickets from changes.** "Prepare Jira ticket" on any change
  (proposed, applied, undone or discarded): an editable title and
  description pre-filled with the request, the requester, what was proposed
  and whether it was applied, plus page, environment, files and the diff.
  With `ALFRED_JIRA_*` set it creates the issue (Jira Cloud REST v3) and
  attaches screenshots; without, "Copy ticket text" and screenshot downloads.
  A change can only be ticketed once; the key is shown on the change.
- **Screenshots.** A "before" screenshot of the selected element (outlined)
  is taken in the browser when a change is requested; "Add screenshot of
  current view" adds an "after". Stored on `ALFRED_SCREENSHOT_DISK`; turn off
  with `ALFRED_SCREENSHOTS=false`. Uses bundled html-to-image (MIT) — no CDN.
- New migration adding screenshots and Jira columns to `alfred_changes`.

### Changed
- Edits must target only the lines they change: a search block longer than
  `alfred.edit.max_search_lines` (60) goes back to the agent. Avoids
  whole-file edits and cuts token use several-fold.
- Change summaries describe the visible result, not how the file was edited.

## [0.2.0] - 2026-10-22

### Added
- **"Change this"**: point at an element, describe a UI change in plain words,
  get a code diff. In `write` mode it can be applied to the files on the
  server and undone; in `propose` mode you download a `git apply`-able patch.
- Configurable per environment: `ALFRED_EDIT`, `ALFRED_EDIT_ENVIRONMENTS`
  (default `local`), `ALFRED_EDIT_MODE` (`write` / `propose` / auto).
- `editAlfred` gate (defaults to the verifiers).
- Edits are validated before you see them: only files the agent read, only
  under `alfred.edit.paths`, never deny-listed files, search text must match
  exactly once, PHP and Blade must still parse.
- Apply and undo refuse to overwrite files that changed in the meantime.
- Recent changes list and an "Undo" toast after a change is applied.
- `alfred_changes` table (new migration).

### Changed
- The agent loop is shared between explanations and changes
  (`Agent::loop()`); `Agent::readTools()` exposes the read-only tool set.
- Overlay buttons and diff styling; arrows in code no longer render as
  font ligatures.

## [0.1.0] - 2026-10-22

### Added
- "How is this calculated?" overlay for any Laravel app (Blade, Livewire,
  Filament, Inertia first load).
- Page recording (route, views, Livewire components, SQL with the code line
  that ran it), OpenAI Responses tool-calling agent with read-only tools.
- Privacy modes (`code_only`, `redacted`, `full`), path allow/deny lists.
- Cached explanations invalidated by source file hashes; verification,
  editing and regeneration for verifiers.
- "Show the records behind this" drill-down (local, read-only).

[0.9.2]: https://github.com/Atoria/alfred/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/Atoria/alfred/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/Atoria/alfred/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/Atoria/alfred/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/Atoria/alfred/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/Atoria/alfred/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/Atoria/alfred/compare/v0.4.2...v0.5.0
[0.4.2]: https://github.com/Atoria/alfred/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/Atoria/alfred/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/Atoria/alfred/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/Atoria/alfred/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/Atoria/alfred/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Atoria/alfred/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Atoria/alfred/releases/tag/v0.1.0
