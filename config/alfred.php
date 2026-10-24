<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Alfred
    |--------------------------------------------------------------------------
    |
    | Master switch. When false nothing is injected, recorded or routed.
    |
    */

    'enabled' => (bool) env('ALFRED_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Alfred registers a handful of JSON endpoints used by the in-page overlay.
    | They run under the "web" middleware group (session + CSRF) by default.
    | If your users sign in through a different guard / stack (e.g. a
    | Filament panel with its own auth guard), add that auth middleware here.
    |
    */

    'path' => env('ALFRED_PATH', '_alfred'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Overlay injection
    |--------------------------------------------------------------------------
    |
    | Alfred runs as global middleware, so it works with any stack: the web
    | group, Filament panels, Livewire, Inertia's first page load.
    |
    | When true, Alfred appends its overlay script to every HTML response for
    | users who pass the "useAlfred" gate. Set to false and use the @alfred
    | Blade directive if you prefer to place it yourself.
    |
    | "except" takes request path patterns (Str::is syntax) to never inject on.
    |
    */

    'inject' => (bool) env('ALFRED_INJECT', true),

    'except' => [
        'livewire/*',
        '_debugbar/*',
        'horizon/*',
        'telescope/*',
    ],

    'position' => env('ALFRED_POSITION', 'bottom-right'), // bottom-right | bottom-left

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | What people see. "name" replaces "Alfred" in the panel, messages and
    | tickets. "icon" is what the button shows: an image URL or path
    | (https://…, /images/helper.png, data:image/…), or a short text or emoji
    | ("?", "🤖", "AI"). "color" is the accent colour (any CSS colour).
    |
    */

    'branding' => [
        'name' => env('ALFRED_NAME', 'Alfred'),
        'icon' => env('ALFRED_ICON', '?'),
        'color' => env('ALFRED_COLOR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Who can do what
    |--------------------------------------------------------------------------
    |
    | Alfred uses six Laravel Gates. If your app defines them (in a service
    | provider: Gate::define('useAlfred', fn ($user) => ...)) yours win.
    | Otherwise these defaults apply:
    |
    |  useAlfred         — signed-in users whose email is in "users" or
    |                      "verifiers". Nobody else sees Alfred at all: no
    |                      button, no script, nothing recorded.
    |  verifyAlfred      — users whose email is listed in "verifiers"
    |  viewAlfredRecords — same as verifyAlfred
    |  editAlfred        — same as verifyAlfred (see "edit" below)
    |  viewAlfredCode    — same as verifyAlfred: the "For developers" tab
    |  reportAlfred      — same as useAlfred: "Report / request" tickets
    |
    | Emails can be exact (ana@acme.com) or a pattern (*@acme.com).
    |
    | Verifiers can edit, approve and regenerate explanations, and see the
    | technical notes (file references) behind them.
    |
    | viewAlfredRecords controls "show the records behind this". It re-runs
    | the page's own query, but as SELECT * — so it can reveal columns the
    | page itself does not display. Open it up deliberately.
    |
    */

    'gates' => [
        'use' => 'useAlfred',
        'verify' => 'verifyAlfred',
        'records' => 'viewAlfredRecords',
        'edit' => 'editAlfred',
        'code' => 'viewAlfredCode',
        'report' => 'reportAlfred',
        'contribute' => 'contributeAlfredExplanations', // whose new explanations are shared (others' stay private to them)
    ],

    'users' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALFRED_USERS', ''))
    ))),

    'verifiers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALFRED_VERIFIERS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Auth guards
    |--------------------------------------------------------------------------
    |
    | Which guards Alfred checks to find the signed-in user, in order. Empty =
    | the app's default guard. Apps with several panels / user tables list
    | them all, e.g. ALFRED_GUARDS=web,tenant or ALFRED_GUARDS=web,admin.
    |
    */

    'guards' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALFRED_GUARDS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Signed-out visitors
    |--------------------------------------------------------------------------
    |
    | Alfred on pages nobody is signed in to (login, sign-up, public pages).
    | Off by default: on a public page any visitor could otherwise spend your
    | AI credits and read about your code.
    |
    | Meant for local / staging / QA. It is refused in production unless you
    | add "production" to ALFRED_GUESTS_ENVIRONMENTS (Alfred then logs a
    | warning every hour).
    |
    | On local with no key set, it simply works. Anywhere else, set
    | ALFRED_ACCESS_KEY and open /_alfred/access?key=<key> once in a browser
    | to unlock it there. Change the key to lock every browser out again.
    |
    | Guests can explain values and report / request. Never edit, code or
    | records — those always need a signed-in verifier.
    |
    */

    'guests' => [
        'enabled' => (bool) env('ALFRED_GUESTS', false),
        'access_key' => env('ALFRED_ACCESS_KEY'),
        'environments' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ALFRED_GUESTS_ENVIRONMENTS', 'local,development,dev,staging,testing,qa'))
        ))),
        'remember_days' => (int) env('ALFRED_ACCESS_DAYS', 30),
        'report' => (bool) env('ALFRED_GUESTS_REPORT', true), // "Report or request" for guests
        // Guests sending a report straight to the tracker. Off: their reports are
        // kept for a signed-in person to review and send (or copy the text).
        'tickets' => (bool) env('ALFRED_GUESTS_TICKETS', false),
        'rate_limit' => (int) env('ALFRED_GUESTS_RATE_LIMIT', 3), // new explanations per IP per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy
    |--------------------------------------------------------------------------
    |
    | "auto" detects stancl/tenancy and spatie/laravel-multitenancy. Alfred
    | then keeps its tables on the central connection, runs its own requests
    | in the tenant of the page they came from (via a signed reference, so a
    | user can't switch themselves into another tenant), and keeps every
    | record (explanations, changes, follow-ups, tasks) to its own tenant.
    |
    | Values: auto | stancl | spatie | none | a class implementing
    | Atoria\Alfred\Tenancy\TenancyAdapter.
    |
    */

    'tenancy' => [
        'driver' => env('ALFRED_TENANCY', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM provider
    |--------------------------------------------------------------------------
    |
    | OpenAI's Responses API with function calling. "base_url" lets you point
    | at any compatible endpoint (Azure OpenAI, a gateway, a proxy).
    |
    */

    // "openai" (built in) or "laragent" (any provider LarAgent supports).
    'provider' => env('ALFRED_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('ALFRED_OPENAI_API_KEY', env('OPENAI_API_KEY')),
        'organization' => env('ALFRED_OPENAI_ORGANIZATION', env('OPENAI_ORGANIZATION')),
        'base_url' => env('ALFRED_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('ALFRED_OPENAI_MODEL', 'gpt-5-mini'),
        'reasoning_effort' => env('ALFRED_OPENAI_REASONING_EFFORT', 'low'), // null to omit (non-reasoning models)
        'timeout' => (int) env('ALFRED_OPENAI_TIMEOUT', 120),
    ],

    /*
    | ALFRED_PROVIDER=laragent: run Alfred on Claude, Gemini, Groq, Ollama,
    | OpenRouter or any OpenAI-compatible API through LarAgent
    | (composer require maestroerror/laragent, PHP 8.3+). The provider and its
    | API key come from config/laragent.php ("providers"); Alfred only picks
    | which one. Recommended: "openai_responses" for OpenAI, "claude",
    | "gemini" (OpenAI-compatible endpoint, supports forcing tools and
    | reasoning), "openrouter", "ollama".
    */
    'laragent' => [
        'provider' => env('ALFRED_LARAGENT_PROVIDER', 'default'),
        'model' => env('ALFRED_LARAGENT_MODEL'), // null = the provider's model in config/laragent.php
        // auto: where the API supports it in a tool loop (see README) | on | off
        'reasoning' => env('ALFRED_LARAGENT_REASONING', 'auto'),
        'reasoning_effort' => env('ALFRED_LARAGENT_REASONING_EFFORT', env('ALFRED_OPENAI_REASONING_EFFORT', 'low')),
        'max_tokens' => (int) env('ALFRED_LARAGENT_MAX_TOKENS', 16000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | What may leave your server and reach the LLM provider:
    |
    |  code_only — source code, SQL *templates* (placeholders, no bindings),
    |              route/view/component names and the element's labels with
    |              digits masked. Values shown on the page are never sent.
    |  redacted  — as above, plus the element's visible text after masking
    |              emails, phone numbers, long digit runs and numbers.
    |  full      — everything including the element's text and query
    |              bindings. Local development with fake data only.
    |
    | Drill-down rows ("show the records behind this") are always produced
    | locally and only ever sent to the user's browser.
    |
    */

    'privacy' => env('ALFRED_PRIVACY', 'code_only'),

    /*
    |--------------------------------------------------------------------------
    | Code the agent may read
    |--------------------------------------------------------------------------
    |
    | The agent explores your code with read-only tools. It can only see
    | files under these directories (relative to base_path), and never
    | anything matching "deny". Keep config/ out unless you are sure no
    | secrets are hard-coded there.
    |
    */

    'base_path' => env('ALFRED_BASE_PATH'), // null = base_path()

    // Folders under "edit.paths" are always readable too (a change starts
    // by reading the file).
    'paths' => [
        'app',
        'resources/views',
        'resources/js',
        'resources/css',
        'routes',
        'database/migrations',
        'src',
    ],

    // Matched case-insensitively against the path and the file name.
    'deny' => [
        '.env*',
        '*.pem',
        '*.key',
        '*.p12',
        '*.pfx',
        '*.keystore',
        'id_rsa*',
        'auth.json',
        '*secret*',
        '*credential*',
        '*password*',
        'storage/*',
        'vendor/*',
        'node_modules/*',
        '.git/*',
    ],

    'extensions' => ['php', 'blade.php', 'js', 'mjs', 'ts', 'vue', 'jsx', 'tsx', 'svelte', 'css', 'scss', 'sass', 'less', 'sql'],

    /*
    |--------------------------------------------------------------------------
    | Agent limits
    |--------------------------------------------------------------------------
    */

    'agent' => [
        'max_steps' => (int) env('ALFRED_MAX_STEPS', 14),
        'max_file_bytes' => 60_000,
        'max_search_results' => 40,
        'locale' => env('ALFRED_LOCALE', 'en'), // language of explanations
        'audience' => env('ALFRED_AUDIENCE', 'a non-technical business user of this application'),
    ],

    // New explanations and changes run right after the response is sent and
    // the overlay checks back, so load balancers and proxies with a 30–100 s
    // limit (Laravel Cloud, nginx, Cloudflare) don't cut them off with a 504.
    'async' => [
        'enabled' => (bool) env('ALFRED_ASYNC', true),
        'timeout' => (int) env('ALFRED_ASYNC_TIMEOUT', 600), // seconds before a task counts as stuck
        'max_running' => (int) env('ALFRED_ASYNC_MAX_RUNNING', 3), // AI tasks one person may have running at once
    ],

    // Follow-up questions under an explanation ("Does this include
    // refunds?"). Private to the person asking, kept as long as the page.
    'followups' => [
        'enabled' => (bool) env('ALFRED_FOLLOWUPS', true),
        'max_steps' => (int) env('ALFRED_FOLLOWUP_MAX_STEPS', 8),
        'max_turns' => (int) env('ALFRED_FOLLOWUP_MAX_TURNS', 10),
    ],

    // Text people type for the AI (follow-ups, change requests, refine
    // feedback) is checked first. Attempts to reach another tenant's or
    // person's data, secrets or Alfred's instructions are refused and
    // recorded (alfred_security_events, a SuspiciousRequestBlocked event and
    // a log warning).
    'screening' => [
        'enabled' => (bool) env('ALFRED_SCREENING', true),
        'log_channel' => env('ALFRED_SECURITY_LOG_CHANNEL'), // null = default channel
    ],

    'security_events_table' => 'alfred_security_events',

    // Generations per user per minute (cached answers are not limited).
    'rate_limit' => (int) env('ALFRED_RATE_LIMIT', 6),

    /*
    |--------------------------------------------------------------------------
    | Request recording
    |--------------------------------------------------------------------------
    |
    | For users who pass the gate, Alfred records what each page did: route,
    | controller, views, Livewire components and SQL queries (with the code
    | location that ran them). This is the agent's starting point. Livewire
    | follow-up requests from the same page are appended.
    |
    */

    'recording' => [
        'store' => env('ALFRED_CACHE_STORE'), // null = default cache store
        'ttl' => (int) env('ALFRED_CONTEXT_TTL', 3600), // seconds
        'max_queries' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Drill-down
    |--------------------------------------------------------------------------
    |
    | "Show the records behind this" re-runs the query the agent identified,
    | with the bindings from the user's own request, turning an aggregate
    | (COUNT/SUM/AVG...) into the rows it was computed from. Read-only SELECTs
    | only, inside a rolled-back transaction.
    |
    */

    'drilldown' => [
        'enabled' => (bool) env('ALFRED_DRILLDOWN', true),
        'max_rows' => 100,
        'hidden_columns' => [
            'password', 'remember_token', 'api_token', 'token', 'secret',
            'two_factor_secret', 'two_factor_recovery_codes',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI changes ("Change this")
    |--------------------------------------------------------------------------
    |
    | Point at an element, describe a change ("move it a bit left", "make this
    | badge green") and Alfred proposes a code change as a diff.
    |
    | Off by default. When on, it is available only in the listed app
    | environments (APP_ENV) and only to users who pass the "editAlfred" gate
    | (default: the same people as verifyAlfred).
    |
    | mode:
    |  write   — "Apply" writes the change to the files on this server and the
    |            page reloads with it. "Undo" restores the original. Use where
    |            the code on disk is yours to change (local, a staging box).
    |  propose — nothing is written. The change is stored and can be
    |            downloaded as a patch (git apply) to put in your repo.
    |  null    — write when APP_ENV is local, propose everywhere else.
    |
    | Note: on platforms that rebuild from git on deploy (Railway, Vapor,
    | Forge zero-downtime...) written changes disappear on the next deploy,
    | and with several server instances only one of them gets the change.
    | Use "propose" there.
    |
    */

    'edit' => [
        'enabled' => (bool) env('ALFRED_EDIT', false),
        'environments' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ALFRED_EDIT_ENVIRONMENTS', 'local'))
        ))),
        'mode' => env('ALFRED_EDIT_MODE'), // write | propose | null (auto)

        // Only these may be changed (a subset of what the agent can read),
        // never anything matching "deny" above.
        'paths' => [
            'app',
            'resources/views',
            'resources/css',
            'resources/js',
        ],

        // Never changed, even inside "paths": app wiring, security and
        // background code is not "UI". (Plus everything in "deny" above.)
        'deny' => [
            'app/Providers/*',
            'app/Policies/*',
            'app/Http/Middleware/*',
            'app/Http/Kernel.php',
            'app/Console/*',
            'app/Exceptions/*',
            'app/Jobs/*',
            'app/Listeners/*',
            'app/Observers/*',
        ],

        // "Preview on this page": the requester sees the proposed change
        // rendered for real, only in their own session; nothing is written
        // to the codebase. Runs the proposed code for that user. Disabled
        // automatically on long-running servers (Octane).
        'preview' => (bool) env('ALFRED_EDIT_PREVIEW', true),
        'preview_minutes' => (int) env('ALFRED_PREVIEW_MINUTES', 120), // a preview switches itself off after this
        // Multi-tenant apps: "write" mode on a tenant's pages would change the code
        // every tenant runs, so there it falls back to "propose" unless this is on.
        'write_in_tenants' => (bool) env('ALFRED_EDIT_WRITE_IN_TENANTS', false),
        'preview_keep_days' => (int) env('ALFRED_PREVIEW_KEEP_DAYS', 7), // unused preview copies are removed after this

        'max_files' => 5,       // per change
        'max_search_lines' => 60, // keep edits targeted (cheaper, clearer diffs)
        'max_steps' => (int) env('ALFRED_EDIT_MAX_STEPS', 16),

        // Edits need precise copying; give the model more thought than for
        // explanations. Empty = use ALFRED_OPENAI_REASONING_EFFORT.
        'reasoning_effort' => env('ALFRED_EDIT_REASONING_EFFORT', 'medium'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Report / request
    |--------------------------------------------------------------------------
    |
    | A third choice after clicking an element: describe a problem or a wish,
    | and Alfred prepares a ticket with a screenshot of the element and where
    | it lives in the code (route, controller, component, views, the lines
    | that load its data). No AI involved. Who may use it: the reportAlfred
    | gate (default: everyone who can use Alfred).
    |
    */

    'report' => [
        'enabled' => (bool) env('ALFRED_REPORT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tickets
    |--------------------------------------------------------------------------
    |
    | "Prepare ticket" on any change or request: an editable title and
    | description, page details, where it lives in the code, the diff and
    | screenshots. Without a tracker the text can still be copied and the
    | screenshots downloaded.
    |
    | "trackers" lists which trackers to offer, in order; the first is the
    | default and people can pick another one when creating the ticket.
    | Only trackers whose settings below are filled in are shown.
    |
    |   ALFRED_TICKETS=jira               one tracker
    |   ALFRED_TICKETS=github,trello      several: a "Send to" choice appears
    |
    | Built in: jira, github, gitlab, linear, trello, clickup, asana.
    | Add your own by implementing Atoria\Alfred\Tickets\Trackers\Tracker and
    | listing it in "custom" (name => class), then use its name above.
    |
    */

    'tickets' => [
        'trackers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ALFRED_TICKETS', ''))
        ))),
        'labels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ALFRED_TICKET_LABELS', 'alfred'))
        ))),
        'include_diff' => (bool) env('ALFRED_TICKET_INCLUDE_DIFF', env('ALFRED_JIRA_INCLUDE_DIFF', true)),
        'custom' => [
            // 'youtrack' => App\Alfred\YouTrackTracker::class,
        ],
    ],

    /*
    | Settings per tracker. "labels" overrides ALFRED_TICKET_LABELS for that
    | tracker; leave it empty to use the shared list.
    */

    'trackers' => [
        // Jira Cloud. API token: id.atlassian.com → Security → API tokens.
        'jira' => [
            'enabled' => (bool) env('ALFRED_JIRA', false), // same as ALFRED_TICKETS=jira
            'url' => env('ALFRED_JIRA_URL'),            // https://your-company.atlassian.net
            'email' => env('ALFRED_JIRA_EMAIL'),        // the account the token belongs to
            'token' => env('ALFRED_JIRA_TOKEN'),
            'project' => env('ALFRED_JIRA_PROJECT'),    // project key, e.g. PROJ
            'issue_type' => env('ALFRED_JIRA_ISSUE_TYPE', 'Task'),
            'labels' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALFRED_JIRA_LABELS', ''))))),
        ],

        // GitHub Issues. Fine-grained token with "Issues: Read and write" on the repo.
        // GitHub's API can't upload images, so issues link to the screenshots in Alfred.
        'github' => [
            'token' => env('ALFRED_GITHUB_TOKEN'),
            'repo' => env('ALFRED_GITHUB_REPO'),        // owner/repo
            'api_url' => env('ALFRED_GITHUB_API_URL', 'https://api.github.com'), // GitHub Enterprise: https://github.example.com/api/v3
            'labels' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALFRED_GITHUB_LABELS', ''))))),
        ],

        // GitLab issues (gitlab.com or self-hosted). Token with the "api" scope.
        'gitlab' => [
            'url' => env('ALFRED_GITLAB_URL', 'https://gitlab.com'),
            'token' => env('ALFRED_GITLAB_TOKEN'),
            'project' => env('ALFRED_GITLAB_PROJECT'),  // numeric id or group/project
            'labels' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALFRED_GITLAB_LABELS', ''))))),
        ],

        // Linear. Personal API key: Settings → Security & access → API.
        'linear' => [
            'api_key' => env('ALFRED_LINEAR_API_KEY'),
            'team' => env('ALFRED_LINEAR_TEAM'),        // team key (ENG) or id
        ],

        // Trello. Key and token: trello.com/power-ups/admin → your Power-Up → API key.
        'trello' => [
            'key' => env('ALFRED_TRELLO_KEY'),
            'token' => env('ALFRED_TRELLO_TOKEN'),
            'list_id' => env('ALFRED_TRELLO_LIST_ID'),  // the list new cards go to
            'list_name' => env('ALFRED_TRELLO_LIST_NAME'), // optional, shown on the button
            'label_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALFRED_TRELLO_LABEL_IDS', ''))))),
        ],

        // ClickUp. Personal token: Settings → Apps → API token (starts with pk_).
        'clickup' => [
            'token' => env('ALFRED_CLICKUP_TOKEN'),
            'list_id' => env('ALFRED_CLICKUP_LIST_ID'),
            'list_name' => env('ALFRED_CLICKUP_LIST_NAME'), // optional, shown on the button
            'labels' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALFRED_CLICKUP_TAGS', ''))))),
        ],

        // Asana. Personal access token: app.asana.com/0/my-apps.
        'asana' => [
            'token' => env('ALFRED_ASANA_TOKEN'),
            'project' => env('ALFRED_ASANA_PROJECT'),   // project gid (the number in the project URL)
            'project_name' => env('ALFRED_ASANA_PROJECT_NAME'), // optional, shown on the button
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pull requests
    |--------------------------------------------------------------------------
    |
    | "Open pull request" on a change, and "also open a pull request" when
    | creating its ticket. Alfred reads each changed file from the base
    | branch through the provider's API, re-applies the change's edits,
    | commits them to a new branch and opens a (draft) pull request for
    | review. Nothing is written on the server and no git is needed there,
    | so it works on staging in "propose" mode.
    |
    |   ALFRED_PULL_REQUESTS=github            one provider
    |   ALFRED_PULL_REQUESTS=github,gitlab     several: people pick one
    |
    | Built in: github, gitlab, bitbucket. Add your own by implementing
    | Atoria\Alfred\PullRequests\Providers\GitProvider and listing it in "custom".
    | Only people allowed to change the UI (editAlfred) can open them.
    |
    */

    'pull_requests' => [
        'providers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ALFRED_PULL_REQUESTS', ''))
        ))),
        'base' => env('ALFRED_PR_BASE'),                       // empty: the repository's default branch
        'branch_prefix' => env('ALFRED_PR_BRANCH_PREFIX', 'alfred/'),
        'draft' => (bool) env('ALFRED_PR_DRAFT', true),
        'path_prefix' => env('ALFRED_PR_PATH_PREFIX', ''),     // where the app lives inside the repository, e.g. "backend"
        'labels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ALFRED_PR_LABELS', ''))
        ))),
        'custom' => [
            // 'azure' => App\Alfred\AzureReposProvider::class,
        ],
    ],

    /*
    | Repository settings per provider. GitHub and GitLab share their token
    | and repository with the ticket trackers above, so one token with the
    | right permissions covers both (see the README).
    */

    'git' => [
        'github' => [
            'token' => env('ALFRED_GITHUB_TOKEN'),   // needs Contents + Pull requests: Read and write
            'repo' => env('ALFRED_GITHUB_REPO'),     // owner/repo
            'api_url' => env('ALFRED_GITHUB_API_URL', 'https://api.github.com'),
        ],
        'gitlab' => [
            'url' => env('ALFRED_GITLAB_URL', 'https://gitlab.com'),
            'token' => env('ALFRED_GITLAB_TOKEN'),   // "api" scope, Developer role or higher
            'project' => env('ALFRED_GITLAB_PROJECT'),
        ],
        'bitbucket' => [
            'repo' => env('ALFRED_BITBUCKET_REPO'),  // workspace/repo
            'token' => env('ALFRED_BITBUCKET_TOKEN'), // repository access token, or an API token with ALFRED_BITBUCKET_EMAIL
            'email' => env('ALFRED_BITBUCKET_EMAIL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Screenshots
    |--------------------------------------------------------------------------
    |
    | Taken in the requester's browser (nothing leaves your server unless a
    | ticket is created) and stored on this filesystem disk. They show
    | whatever was on screen — real data on staging — so turn them off if
    | that should not end up in your tracker.
    |
    */

    'screenshots' => [
        'enabled' => (bool) env('ALFRED_SCREENSHOTS', true),
        'disk' => env('ALFRED_SCREENSHOT_DISK', 'local'),
        'max_kb' => 6000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'table' => 'alfred_explanations',

    'changes_table' => 'alfred_changes',

    'versions_table' => 'alfred_explanation_versions',

    'usage_table' => 'alfred_usage',

    // Tokens used per day, person and kind (see `php artisan alfred:usage`),
    // and optional daily budgets: once spent, new AI work is refused until
    // midnight (cached explanations keep working). 0 = no budget.
    'usage' => [
        'enabled' => (bool) env('ALFRED_USAGE', true),
        'daily_tokens' => (int) env('ALFRED_DAILY_TOKENS', 0), // per tenant (the whole app without tenancy)
        'daily_tokens_per_user' => (int) env('ALFRED_DAILY_TOKENS_PER_USER', 0),
    ],

    // Change records (and their screenshots) older than this are pruned by
    // Laravel's model:prune (schedule it). Applied changes are kept unless
    // prune_applied is on. 0 = keep forever.
    'retention' => [
        'changes_days' => (int) env('ALFRED_KEEP_CHANGES_DAYS', 0),
        'prune_applied' => (bool) env('ALFRED_PRUNE_APPLIED', false),
        'security_events_days' => (int) env('ALFRED_KEEP_SECURITY_EVENTS_DAYS', 180),
    ],

    // Every generation, edit, verification and restore of an explanation is
    // kept as a version (verifiers can view and restore them).
    'history' => [
        'keep' => (int) env('ALFRED_HISTORY_KEEP', 50), // versions per explanation
    ],

    // null = the tenancy package's central connection if there is one, else the default.
    'connection' => env('ALFRED_DB_CONNECTION'),

];
