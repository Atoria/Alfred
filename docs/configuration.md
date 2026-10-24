# Configuration reference

Everything is in `config/alfred.php` (publish it with `php artisan vendor:publish --tag=alfred-config`), and most options can be set from `.env`. Tracker and git provider settings are in [trackers.md](trackers.md) and [pull-requests.md](pull-requests.md).

| env | default | |
|---|---|---|
| `ALFRED_ENABLED` | `false` | master switch |
| `ALFRED_OPENAI_API_KEY` | `OPENAI_API_KEY` | |
| `ALFRED_OPENAI_MODEL` | `gpt-5-mini` | any Responses API model that supports function calling |
| `ALFRED_OPENAI_REASONING_EFFORT` | `low` | leave empty for non-reasoning models |
| `ALFRED_OPENAI_BASE_URL` | `https://api.openai.com/v1` | for Azure OpenAI, a gateway or a proxy |
| `ALFRED_OPENAI_ORGANIZATION` | `OPENAI_ORGANIZATION` | |
| `ALFRED_OPENAI_TIMEOUT` | `120` | seconds per AI call |
| `ALFRED_PROVIDER` | `openai` | `openai` (built in) or `laragent` ([any LarAgent provider](#6-claude-gemini-groq-ollama-or-openrouter-through-laragent)) |
| `ALFRED_LARAGENT_PROVIDER` | `default` | provider key in `config/laragent.php` |
| `ALFRED_LARAGENT_MODEL` | provider's model | |
| `ALFRED_LARAGENT_REASONING` | `auto` | `auto`, `on` or `off` |
| `ALFRED_LARAGENT_REASONING_EFFORT` | `ALFRED_OPENAI_REASONING_EFFORT` | |
| `ALFRED_LARAGENT_MAX_TOKENS` | `16000` | longest answer per step |
| `ALFRED_PRIVACY` | `code_only` | `code_only`, `redacted` or `full` |
| `ALFRED_USERS` | – | who can see Alfred: comma-separated emails or patterns (`*@acme.com`) |
| `ALFRED_VERIFIERS` | – | who can verify, see code and records, and change: emails or patterns |
| `ALFRED_GUARDS` | default guard | auth guards to look for the user on, in order, e.g. `web,tenant` |
| `ALFRED_GUESTS` | `false` | Alfred for signed-out visitors ([not for production](#signed-out-visitors-login-pages-public-pages)) |
| `ALFRED_ACCESS_KEY` | – | the key in the private access link for guests |
| `ALFRED_GUESTS_ENVIRONMENTS` | `local,development,dev,staging,testing,qa` | where guests are allowed |
| `ALFRED_GUESTS_REPORT` | `true` | "Report or request…" for guests |
| `ALFRED_GUESTS_TICKETS` | `false` | guests may send their reports straight to the tracker |
| `ALFRED_GUESTS_RATE_LIMIT` | `3` | new explanations per IP per minute for guests |
| `ALFRED_ACCESS_DAYS` | `30` | how long the access link's cookie lasts |
| `ALFRED_TENANCY` | `auto` | `auto`, `stancl`, `spatie`, `none` or your adapter class |
| `ALFRED_DB_CONNECTION` | central / default | connection for Alfred's tables |
| `ALFRED_BASE_PATH` | project root | where the agent reads code from |
| `ALFRED_LOCALE` | `en` | language of the explanations |
| `ALFRED_AUDIENCE` | a non-technical business user | who explanations are written for |
| `ALFRED_MAX_STEPS` | `14` | how many tool calls the agent may make before answering |
| `ALFRED_EDIT_MAX_STEPS` | – | tool calls for a change (see `config/alfred.php`) |
| `ALFRED_FOLLOWUPS` | `true` | follow-up questions under explanations |
| `ALFRED_FOLLOWUP_MAX_STEPS` | `8` | AI steps per follow-up |
| `ALFRED_FOLLOWUP_MAX_TURNS` | `10` | follow-ups per explanation and person |
| `ALFRED_RATE_LIMIT` | `6` | new explanations or changes per user per minute (cached answers don't count) |
| `ALFRED_SCREENING` | `true` | refuse and record attempts to reach other tenants' or people's data, secrets or Alfred's instructions |
| `ALFRED_SECURITY_LOG_CHANNEL` | default channel | log channel for refused requests |
| `ALFRED_USAGE` | `true` | count AI calls and tokens (`php artisan alfred:usage`) |
| `ALFRED_DAILY_TOKENS` | `0` | daily token budget per tenant, or for the whole app without tenancy (0 = none) |
| `ALFRED_DAILY_TOKENS_PER_USER` | `0` | daily token budget per person (0 = none) |
| `ALFRED_HISTORY_KEEP` | `50` | versions kept per explanation |
| `ALFRED_DRILLDOWN` | `true` | "Show the records behind this" |
| `ALFRED_EDIT` | `false` | changing the UI |
| `ALFRED_EDIT_ENVIRONMENTS` | `local` | where changing is allowed (APP_ENV values, or `*`) |
| `ALFRED_EDIT_MODE` | automatic | `write`, `propose`, or empty for write on local and propose elsewhere |
| `ALFRED_EDIT_REASONING_EFFORT` | `medium` | reasoning effort for changes |
| `ALFRED_EDIT_PREVIEW` | `true` | "Preview on this page" |
| `ALFRED_PREVIEW_MINUTES` | `120` | a preview switches itself off after this |
| `ALFRED_EDIT_WRITE_IN_TENANTS` | `false` | allow `write` mode on tenant pages (otherwise `propose` there) |
| `ALFRED_REPORT` | `true` | "Report or request…" |
| `ALFRED_TICKETS` | – | trackers to offer, in order: `jira`, `github`, `gitlab`, `linear`, `trello`, `clickup`, `asana` or your own (plus their settings above) |
| `ALFRED_TICKET_LABELS` | `alfred` | labels/tags on every ticket |
| `ALFRED_TICKET_INCLUDE_DIFF` | `true` | page details, code location and diff in tickets |
| `ALFRED_PULL_REQUESTS` | – | git providers for pull requests, in order: `github`, `gitlab`, `bitbucket` or your own |
| `ALFRED_PR_BASE` | default branch | branch pull requests target |
| `ALFRED_PR_DRAFT` | `true` | open pull requests as drafts |
| `ALFRED_PR_BRANCH_PREFIX` | `alfred/` | prefix for new branches |
| `ALFRED_PR_PATH_PREFIX` | – | the app's folder inside the repository |
| `ALFRED_PR_LABELS` | – | labels on pull requests |
| `ALFRED_SCREENSHOTS` | `true` | before/after screenshots |
| `ALFRED_SCREENSHOT_DISK` | `local` | filesystem disk for screenshots |
| `ALFRED_INJECT` | `true` | add the overlay automatically (otherwise use `@alfred` in your layout) |
| `ALFRED_PATH` | `_alfred` | URL prefix for Alfred's endpoints |
| `ALFRED_POSITION` | `bottom-right` | or `bottom-left` |
| `ALFRED_NAME` | `Alfred` | the name people see |
| `ALFRED_ICON` | `?` | the button: an image path or URL, or up to 3 characters |
| `ALFRED_COLOR` | – | accent colour (CSS colour) |
| `ALFRED_ASYNC` | `true` | run new explanations and changes after the response, so proxies can't time them out (504) |
| `ALFRED_ASYNC_TIMEOUT` | `600` | seconds before a background task counts as stuck |
| `ALFRED_ASYNC_MAX_RUNNING` | `3` | AI tasks one person may have running at once |
| `ALFRED_CACHE_STORE` | default store | where page recordings and background results are kept |
| `ALFRED_CONTEXT_TTL` | `3600` | how long a page stays explainable, in seconds |
| `ALFRED_KEEP_CHANGES_DAYS` | `0` | prune change records older than this with `model:prune` (0 = keep) |
| `ALFRED_PRUNE_APPLIED` | `false` | also prune applied changes |
| `ALFRED_KEEP_SECURITY_EVENTS_DAYS` | `180` | prune refused-request records older than this |

## Commands

```bash
php artisan alfred:doctor              # check the setup (never prints secrets)
php artisan alfred:usage               # AI calls and tokens, --by=kind|user|tenant|model|day --days=7

php artisan alfred:clear               # delete cached explanations (every tenant's)
php artisan alfred:clear --stale       # only those whose code has changed
php artisan alfred:clear --unverified  # keep verified ones
php artisan alfred:clear --previews    # remove preview copies in storage/framework/alfred-preview
```

`php artisan about` also shows an Alfred section.

## Housekeeping

Old change records (with their screenshots) and records of refused requests can be pruned with Laravel's `model:prune`. Schedule it, for example in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [
    \Atoria\Alfred\Changes\Change::class,
    \Atoria\Alfred\Security\SecurityEvent::class,
]])->daily();
```

Changes are kept forever unless `ALFRED_KEEP_CHANGES_DAYS` is set, and applied changes are kept unless `ALFRED_PRUNE_APPLIED=true`.

Preview copies clean themselves up: Alfred removes them once a change is applied, discarded or deleted, or after 7 days without a preview (`ALFRED_PREVIEW_KEEP_DAYS`). The folder has its own `.gitignore`, so it never shows up in git.
