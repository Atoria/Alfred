# Configuration reference

Every option in `.env`, grouped by topic. The default is what you get when the line is missing. Everything else lives in `config/alfred.php` (`php artisan vendor:publish --tag=alfred-config`), where each option is commented. After changing `.env`, run `php artisan optimize:clear`; `php artisan alfred:doctor` checks the result.

## Switching it on, and how it looks

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_ENABLED` | `false` | Master switch. Off = no button, no recording, no endpoints. |
| `ALFRED_INJECT` | `true` | Adds the button to every HTML page automatically. `false` = only where you put `@alfred` in a layout. |
| `ALFRED_PATH` | `_alfred` | URL prefix of Alfred's own endpoints (`/_alfred/...`). Change only if it clashes with a route of yours. |
| `ALFRED_POSITION` | `bottom-right` | Where the button sits: `bottom-right` or `bottom-left`. |
| `ALFRED_NAME` | `Alfred` | The name people see in the panel, messages and tickets. The AI is told its name too. |
| `ALFRED_ICON` | `?` | The button: an image (path in `public/`, a URL or a data: URI), or up to 3 characters / an emoji. |
| `ALFRED_COLOR` | – | Accent colour, any CSS colour (`#0f766e`, `rgb()`, a name…). |

## Who can use it

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_USERS` | – | Who sees Alfred: comma-separated emails or patterns (`*@medlocums.com`). They can explain and report. Ignored if you define the `useAlfred` gate in code. |
| `ALFRED_VERIFIERS` | – | Everything users can do, plus: verify/edit/regenerate explanations, the developer tab, "Show the records", changes, pull requests. Verifiers always see Alfred. Ignored if you define `verifyAlfred`. |
| `ALFRED_GUARDS` | default guard | Auth guards to look for the signed-in user on, in order (`web,admin`). Only needed if people sign in through more than one guard. |

Finer control (who sees code, records, who creates the shared explanations…) is done with gates in code; see the README's [All the gates](../README.md#all-the-gates).

## Signed-out visitors (staging/dev only, never production)

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_GUESTS` | `false` | Lets people who aren't signed in use Alfred (e.g. to report a problem on the login page). |
| `ALFRED_ACCESS_KEY` | – | Secret key in the private link `/_alfred/access?key=…` that unlocks Alfred in a browser. Required everywhere except local. Change it to lock everyone out. |
| `ALFRED_GUESTS_ENVIRONMENTS` | `local,development,dev,staging,testing,qa` | `APP_ENV` values where guests are allowed. Production is never in the default. |
| `ALFRED_ACCESS_DAYS` | `30` | How long the access link's cookie lasts. |
| `ALFRED_GUESTS_REPORT` | `true` | Guests may use "Report or request". |
| `ALFRED_GUESTS_TICKETS` | `false` | Guests may send their report straight to the tracker. Off = a signed-in person sends it. |
| `ALFRED_GUESTS_RATE_LIMIT` | `3` | New explanations per minute per IP for guests (cached ones are free). |

## AI provider

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_PROVIDER` | `openai` | `openai` (Alfred's own client) or `laragent` (any provider LarAgent supports). |
| `ALFRED_OPENAI_API_KEY` | `OPENAI_API_KEY` | OpenAI key. Also used by LarAgent's OpenAI providers. |
| `ALFRED_OPENAI_ORGANIZATION` | `OPENAI_ORGANIZATION` | OpenAI organization id, if your key needs one. |
| `ALFRED_OPENAI_BASE_URL` | `https://api.openai.com/v1` | Point at Azure OpenAI, a gateway or a proxy that speaks the Responses API. |
| `ALFRED_OPENAI_MODEL` | `gpt-5-mini` | Model for explanations, follow-ups and changes. |
| `ALFRED_OPENAI_REASONING_EFFORT` | `low` | How hard the model thinks for explanations (`low`/`medium`/`high`). Empty for non-reasoning models. |
| `ALFRED_OPENAI_TIMEOUT` | `120` | Seconds to wait for one AI call. |
| `ALFRED_LARAGENT_PROVIDER` | LarAgent's `default_providers`, else `default` | Which provider from `config/laragent.php` (`openai_responses`, `claude`, `gemini`, `ollama`…). Several, comma-separated: the rest are fallbacks. |
| `ALFRED_LARAGENT_MODEL` | provider's model | Model for the first provider. |
| `ALFRED_LARAGENT_REASONING` | `auto` | Send reasoning effort: `auto` (where it works in a tool loop), `on`, `off`. |
| `ALFRED_LARAGENT_REASONING_EFFORT` | `ALFRED_OPENAI_REASONING_EFFORT` | Reasoning effort through LarAgent. |
| `ALFRED_LARAGENT_MAX_TOKENS` | the provider's `default_max_completion_tokens` or `16000`, whichever is larger | Longest reply per step. Raise it if replies get cut off. |
| `ALFRED_LARAGENT_TEMPERATURE` | – (the model's default) | Sampling temperature for Alfred. Not taken from `config/laragent.php`, which is tuned for your app's own agents. |

## Explanations

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_PRIVACY` | `code_only` | What may reach the AI. `code_only`: code and SQL templates, never values on the page. `redacted`: plus the clicked element's text with emails/numbers masked. `full`: everything (local fake data only). |
| `ALFRED_LOCALE` | `en` | Language explanations are written in. |
| `ALFRED_AUDIENCE` | a non-technical business user | Who explanations are written for, in your words. |
| `ALFRED_MAX_STEPS` | `14` | How many tool calls (reading files, searching) the agent may make before answering. |
| `ALFRED_RATE_LIMIT` | `6` | New explanations or changes per person per minute. Cached answers don't count. |
| `ALFRED_FOLLOWUPS` | `true` | "Ask a follow-up" under explanations. |
| `ALFRED_FOLLOWUP_MAX_STEPS` | `8` | AI steps per follow-up. |
| `ALFRED_FOLLOWUP_MAX_TURNS` | `10` | Follow-ups per explanation and person. |
| `ALFRED_DRILLDOWN` | `true` | "Show the records behind this". |
| `ALFRED_HISTORY_KEEP` | `50` | Versions kept per explanation (history/restore). |
| `ALFRED_BASE_PATH` | project root | Folder the agent reads code from. Only for unusual setups. |

## Changing the UI

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_EDIT` | `false` | Turns on "Change this…". |
| `ALFRED_EDIT_ENVIRONMENTS` | `local` | `APP_ENV` values where changes are allowed (`local,staging`, or `*`). |
| `ALFRED_EDIT_MODE` | auto | `write` = Apply/Undo writes to files on this server. `propose` = nothing is written: preview, patch, ticket or pull request. Empty = write on local, propose elsewhere. |
| `ALFRED_EDIT_REASONING_EFFORT` | `medium` | Reasoning effort for changes. |
| `ALFRED_EDIT_MAX_STEPS` | `16` | Tool calls the agent may make for a change. |
| `ALFRED_EDIT_PREVIEW` | `true` | "Preview on this page". |
| `ALFRED_PREVIEW_MINUTES` | `120` | A preview switches itself off after this. |
| `ALFRED_PREVIEW_KEEP_DAYS` | `7` | Unused preview copies in `storage/framework/alfred-preview` are deleted after this. |
| `ALFRED_EDIT_WRITE_IN_TENANTS` | `false` | Multi-tenant apps only: allow `write` mode on tenant pages (otherwise it becomes `propose` there). |

## Reports and tickets

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_REPORT` | `true` | "Report or request…": a ticket with the person's words, a screenshot and the code location, no AI. |
| `ALFRED_TICKETS` | – | Trackers to offer, in order: `jira`, `github`, `gitlab`, `linear`, `trello`, `clickup`, `asana`. The first is the default. |
| `ALFRED_TICKET_LABELS` | `alfred` | Labels/tags added to every ticket. |
| `ALFRED_TICKET_INCLUDE_DIFF` | `true` | Put page details, code location and the diff in tickets (people can untick it). `ALFRED_JIRA_INCLUDE_DIFF` is the older name. |
| `ALFRED_SCREENSHOTS` | `true` | Before/current screenshots on changes and reports. |
| `ALFRED_SCREENSHOT_DISK` | `local` | Filesystem disk screenshots are stored on (e.g. `s3`). |

**Jira**: `ALFRED_JIRA_URL` (https://…atlassian.net), `ALFRED_JIRA_EMAIL` (account the token belongs to), `ALFRED_JIRA_TOKEN` (API token), `ALFRED_JIRA_PROJECT` (project key, e.g. `MED`), `ALFRED_JIRA_ISSUE_TYPE` (default `Task`), `ALFRED_JIRA_LABELS` (instead of the general labels). `ALFRED_JIRA=true` is an older switch, the same as `ALFRED_TICKETS=jira`. Step-by-step setup for each tracker: [trackers.md](trackers.md).

**GitHub Issues**: `ALFRED_GITHUB_TOKEN`, `ALFRED_GITHUB_REPO` (`owner/repo`), `ALFRED_GITHUB_LABELS`, `ALFRED_GITHUB_API_URL` (GitHub Enterprise only).

**GitLab**: `ALFRED_GITLAB_TOKEN`, `ALFRED_GITLAB_PROJECT` (path or id), `ALFRED_GITLAB_URL` (default gitlab.com), `ALFRED_GITLAB_LABELS`.

**Linear**: `ALFRED_LINEAR_API_KEY`, `ALFRED_LINEAR_TEAM` (key like `ENG`, or id).

**Trello**: `ALFRED_TRELLO_KEY`, `ALFRED_TRELLO_TOKEN`, `ALFRED_TRELLO_LIST_ID`, `ALFRED_TRELLO_LIST_NAME` (shown on the button), `ALFRED_TRELLO_LABEL_IDS`.

**ClickUp**: `ALFRED_CLICKUP_TOKEN`, `ALFRED_CLICKUP_LIST_ID`, `ALFRED_CLICKUP_LIST_NAME`, `ALFRED_CLICKUP_TAGS`.

**Asana**: `ALFRED_ASANA_TOKEN`, `ALFRED_ASANA_PROJECT` (number in the project URL), `ALFRED_ASANA_PROJECT_NAME`.

## Pull requests

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_PULL_REQUESTS` | – | Git hosts to offer: `github`, `gitlab`, `bitbucket`. Only people who may edit can open them. |
| `ALFRED_PR_BASE` | default branch | Branch pull requests target. |
| `ALFRED_PR_DRAFT` | `true` | Open them as drafts. |
| `ALFRED_PR_BRANCH_PREFIX` | `alfred/` | Prefix of the branches Alfred creates. |
| `ALFRED_PR_PATH_PREFIX` | – | If the app lives in a subfolder of the repository (e.g. `backend`). |
| `ALFRED_PR_LABELS` | – | Labels on pull requests (GitHub, GitLab). |

More detail, and how tickets and pull requests link up: [pull-requests.md](pull-requests.md). GitHub and GitLab reuse the tracker settings above (one token can do both; GitHub needs Contents + Pull requests read/write). **Bitbucket**: `ALFRED_BITBUCKET_REPO` (`workspace/repo`), `ALFRED_BITBUCKET_TOKEN`, `ALFRED_BITBUCKET_EMAIL` (only with an Atlassian API token).

## Background work, limits and security

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_ASYNC` | `true` | New explanations/changes run after the response and the overlay checks back, so proxies can't cut them off (504). |
| `ALFRED_ASYNC_TIMEOUT` | `600` | Seconds before a background task counts as stuck. |
| `ALFRED_ASYNC_MAX_RUNNING` | `3` | AI tasks one person may have running at once. |
| `ALFRED_CACHE_STORE` | default store | Cache for page recordings and background results. On several servers use `redis` or `database`, not `file`. |
| `ALFRED_CONTEXT_TTL` | `3600` | How long a page stays explainable after it was loaded, in seconds. |
| `ALFRED_SCREENING` | `true` | Refuse and record questions that try to reach another tenant's or person's data, secrets, or Alfred's instructions. |
| `ALFRED_SECURITY_LOG_CHANNEL` | default channel | Log channel for those refusals (e.g. a Slack channel from `config/logging.php`). |

## Usage, budgets and clean-up

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_USAGE` | `true` | Count AI calls and tokens per day, person and kind (`php artisan alfred:usage`). |
| `ALFRED_DAILY_TOKENS` | `0` (none) | Daily token budget for the app (per tenant in multi-tenant apps). Once spent, new AI work waits until midnight; cached answers keep working. |
| `ALFRED_DAILY_TOKENS_PER_USER` | `0` (none) | Daily token budget per person. |
| `ALFRED_KEEP_CHANGES_DAYS` | `0` (keep) | Change records older than this are deleted by `model:prune` (schedule it). |
| `ALFRED_PRUNE_APPLIED` | `false` | Also prune applied changes. |
| `ALFRED_KEEP_SECURITY_EVENTS_DAYS` | `180` | Refused-request records older than this are pruned. |

## Database and tenancy

| Variable | Default | What it does |
|---|---|---|
| `ALFRED_DB_CONNECTION` | central / default | Database connection for Alfred's tables. |
| `ALFRED_TENANCY` | `auto` | `auto` (detects stancl/tenancy or spatie/laravel-multitenancy), `stancl`, `spatie`, `none`, or your adapter class. Without a tenancy package, `auto` simply finds nothing. See the README's [Multi-tenant apps](../README.md#multi-tenant-apps). |

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
