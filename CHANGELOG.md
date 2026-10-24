# Changelog

All notable changes to Alfred are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-24

First public release.

### Explain
- A **?** overlay on any Laravel page (Blade, Livewire, Filament, Inertia):
  click a number, chart, column or badge and ask how it's calculated.
- An AI agent reads your code (read-only) and writes a plain-language
  explanation, plus a **For developers** tab with the code path, queries
  and edge cases.
- Cached per element and kept current: regenerated when its code changes,
  or flagged when a verified one may be out of date.
- **Follow-up questions**, private to the person asking.
- **Show the records behind this**: the rows a value was calculated from,
  read-only, with hidden and sensitive columns masked.
- **Review:** verify, edit, regenerate, with a full version history and
  restore.

### Change
- **Change this:** describe a UI change and get a minimal diff.
- `write` mode applies and undoes it on the server. `propose` mode gives a
  patch, a ticket or a pull request.
- **Preview on this page**, only for the person who asked and only in their
  session, with a recovery page if the change breaks it.
- **Refine** a change with feedback.
- Inertia, Vue, React and Svelte components are changed at the source.

### Tickets and pull requests
- **Report or request** without AI: the person's words, a screenshot and
  where the element lives in the code.
- Tickets go to Jira, GitHub, GitLab, Linear, Trello, ClickUp, Asana or
  your own tracker, with before/current screenshots.
- Draft pull requests on GitHub, GitLab, Bitbucket or your own host, linked
  with their ticket.

### Platforms and models
- OpenAI built in (Responses API). Any LarAgent provider (Claude, Gemini,
  Groq, Ollama, OpenRouter…) with `ALFRED_PROVIDER=laragent`.
- Long work runs after the response, and the overlay polls for the result,
  so proxies can't time it out.
- Multiple auth guards.
- Signed-out "guests" with a private access link, for staging only.
- Branding: name, icon and colour.

### Security and multi-tenancy
- stancl/tenancy and spatie/laravel-multitenancy are supported out of the
  box, or your own adapter. Every record belongs to its tenant, and one
  tenant can never see, use or affect another's.
- Requests asking for another tenant's or person's data, for secrets, or to
  override Alfred's instructions are refused, recorded
  (`alfred_security_events`), logged and dispatched as an event.
- Shared explanations can't be steered through the page or URL.
  `contributeAlfredExplanations` decides who creates them.
- Credentials in code are masked before reaching the AI. Sensitive files
  are never readable, and providers, policies, middleware and similar files
  are never editable.
- Seven gates control who sees Alfred, verifies, sees code, opens records,
  changes the UI, reports and contributes explanations.

### Operations
- Usage tracking per day, person, tenant, kind and model
  (`php artisan alfred:usage`), with optional daily token budgets.
- `php artisan alfred:doctor`, `php artisan alfred:clear`, an
  `php artisan about` section, and `model:prune` for old records.
- Events: `ExplanationGenerated`, `ExplanationVerified`, `FollowUpAnswered`,
  `ChangeProposed`, `ChangeApplied`, `ChangeReverted`, `TicketCreated`,
  `PullRequestOpened`, `SuspiciousRequestBlocked`.

[Unreleased]: https://github.com/Atoria/Alfred/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Atoria/Alfred/releases/tag/v1.0.0
