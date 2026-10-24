# Changelog

All notable changes to Alfred are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - 2026-10-24

### Added
- **LarAgent fallback providers:** `ALFRED_LARAGENT_PROVIDER=openai_responses,claude`,
  or LarAgent's own `default_providers` / `fallback_provider`. A provider
  that fails (outage, rate limit, bad key) hands over to the next one, and
  the switch is logged.
- `ALFRED_LARAGENT_TEMPERATURE`.
- `alfred:doctor` checks each LarAgent provider Alfred will use: model,
  whether a key is set (never the key), and the token limit.

### Changed
- How Alfred uses `config/laragent.php`: the connection comes from LarAgent,
  the task settings from Alfred.
  - **From LarAgent:** driver (or `default_driver`), key, URL, model,
    `parallel_tool_calls`, and fallbacks with their per-provider overrides.
    No need to repeat any of it in Alfred's variables.
  - **Token limit:** the provider's `default_max_completion_tokens` or 16000,
    whichever is larger. LarAgent's stock 10000 no longer cuts Alfred's
    explanations short. `ALFRED_LARAGENT_MAX_TOKENS` sets an exact value.
  - **Temperature and other sampling settings** are not inherited from your
    app's agents. Alfred sends none unless `ALFRED_LARAGENT_TEMPERATURE` is
    set.
- `ALFRED_LARAGENT_PROVIDER` empty now means LarAgent's `default_providers`
  (else `default`), as in LarAgent.

### Fixed
- Recovering from cut-off replies now works with every provider, not only
  LarAgent's OpenAI Responses driver:
  - Alfred's own OpenAI client recognises an incomplete reply and asks again.
  - LarAgent's chat drivers report a cut-off as "finish reason: length"; that
    is now recognised too.
  - A tool call whose arguments aren't valid JSON is no longer run with
    missing input. The model is told, and calls it again.
- Custom `LlmClient` implementations can throw `LlmCutOff` to get the same
  recovery (see docs/ai-providers.md).

## [1.0.3] - 2026-10-24

### Fixed
- With `ALFRED_PROVIDER=laragent`, a reply cut off at the token limit failed
  with "ToolCall arguments must be a valid JSON string". Alfred now asks the
  model once more for a shorter reply. If that is cut off too, it says so,
  names the setting to raise, and logs what happened (token counts, which
  tool), never the content.

## [1.0.2] - 2026-10-24

### Changed
- Code style (Laravel Pint) and CI fixes only. No change in behaviour from
  1.0.0. (There is no 1.0.1 release.)

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

[Unreleased]: https://github.com/Atoria/Alfred/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/Atoria/Alfred/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/Atoria/Alfred/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/Atoria/Alfred/compare/v1.0.0...v1.0.2
[1.0.0]: https://github.com/Atoria/Alfred/releases/tag/v1.0.0
