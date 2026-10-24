# Alfred

[![Latest version](https://img.shields.io/packagist/v/atoria/alfred.svg)](https://packagist.org/packages/atoria/alfred)
[![Tests](https://github.com/Atoria/Alfred/actions/workflows/ci.yml/badge.svg)](https://github.com/Atoria/Alfred/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/atoria/alfred.svg)](LICENSE)

**Point at anything in your Laravel app and ask "how is this calculated?", or "can we change this?"**

Alfred adds a small **?** button to your app. Click it, then click any number, chart, table column or badge:

- **Explain it.** Alfred tells you, in plain language, what the value means, how it's calculated and where the data comes from, and can show the records behind it.
- **Change it.** Describe a UI change ("make this a badge", "move it to the left"). Alfred finds the code and proposes the edit as a diff. You can preview it on the page, apply it, or turn it into a ticket.
- **Report it.** Something wrong or missing? Write it in your own words and Alfred turns it into a ticket with a screenshot of the element and where it lives in the code.

Alfred works this out by reading your code with an AI agent (OpenAI by default). It doesn't need any annotations in your views, and it works with Blade, Livewire, Filament and Inertia (first page load).

```
 Conversion rate                                 ✓ Verified
 ─────────────────────────────────────────────────────────
 The share of visitors this month who placed an order.

 ┃ Orders ÷ unique visitors × 100

 HOW IT IS WORKED OUT
 1. Counts unique visitors for the selected month
 2. Counts orders placed in the same month, excluding test orders
 3. Divides and rounds to one decimal

 WORTH KNOWING
 • Refunded orders still count
 • Months follow the store's time zone

 [ Show the records behind this ]
```

---

## Contents

- [Features](#features)
- [Quick start](#quick-start)
- [Example setups](#example-setups)
- [Customising in code](#customising-in-code)
- [Explaining values](#explaining-values)
- [Changing the UI](#changing-the-ui)
- [Tickets and screenshots](#tickets-and-screenshots)
- [Pull requests](#pull-requests)
- [Security and privacy](#security-and-privacy)
- [Permissions](#permissions)
- [Configuration](#configuration)
- [Limitations](#limitations)
- [Upgrading and versioning](#upgrading-and-versioning)
- [Contributing](#contributing)

More detail in [`docs/`](docs): [AI providers and budgets](docs/ai-providers.md) · [ticket trackers](docs/trackers.md) · [pull requests](docs/pull-requests.md) · [configuration reference](docs/configuration.md).

## Features

| | |
|---|---|
| **Explain** | Plain-language explanation of any value, traced through your controllers, components, services, scopes and SQL, with a separate **For developers** tab showing the code path. |
| **Follow-ups** | Ask more about any explanation ("does this include cancelled shifts?"), then go straight on to a change or ticket that carries what was found. |
| **Records** | "Show the records behind this" turns a count or a sum into the rows it was calculated from. |
| **Review** | Your team can verify, edit or regenerate explanations, with every version kept and restorable. Users then see "✓ Verified" instead of "AI-generated". |
| **Change** | Describe a UI change and get a minimal code diff. Apply and undo it on the server, or download it as a patch. |
| **Preview** | See a proposed change rendered for real, only in your own browser session, without touching the code. |
| **Tickets** | Turn any change or report into a ticket in **Jira, GitHub, GitLab, Linear, Trello, ClickUp or Asana** (or your own tracker), with the request, the diff, where it lives in the code and screenshots. Or copy it for anything else. |
| **Pull requests** | Open a draft pull request on **GitHub, GitLab or Bitbucket** (or your own git host) with a change's code, for a developer to review. Optionally together with its ticket, linked both ways. |
| **Any model** | OpenAI built in. Claude, Gemini, Groq, Ollama, OpenRouter and more through [LarAgent](https://laragent.ai). Usage tracking and optional daily budgets. |
| **Multi-tenant** | stancl/tenancy and spatie/laravel-multitenancy out of the box: every record stays in its tenant. |
| **Report** | Anyone allowed to use Alfred can report a problem or request something about an element: a ticket with their words, a screenshot and the files behind it, no AI involved. |

## Quick start

**Requirements:** PHP 8.2+, Laravel 11, 12 or 13, and an OpenAI API key (or any endpoint compatible with the OpenAI Responses API).

```bash
composer require atoria/alfred
php artisan migrate
```

Using Laravel Sail? Put `./vendor/bin/sail` in front of each command. To try the latest unreleased code, `composer require atoria/alfred:dev-main`.

Add to `.env`:

```dotenv
ALFRED_ENABLED=true
ALFRED_OPENAI_API_KEY=sk-...
ALFRED_USERS=*@example.com            # who sees Alfred: emails or patterns like *@your-company.com
ALFRED_VERIFIERS=you@example.com      # who can review explanations, see code and propose changes
```

**Alfred is hidden from everyone by default.** Only signed-in users whose email is in `ALFRED_USERS` or `ALFRED_VERIFIERS` see it, unless you define your own `useAlfred` gate (see [Who can see Alfred](#who-can-see-alfred)). Everyone else gets no button and no script on the page. Visitors who can't be signed in (no session) aren't recorded at all, and recording stops as soon as Alfred knows a signed-in user isn't allowed.

Then add your own email to `ALFRED_VERIFIERS` (it must be the email you sign in to the app with), run `php artisan optimize:clear`, sign in and reload any page. A **?** appears in the bottom-right corner. Click it (or press **Alt+Shift+A**), then click the thing you want explained.

The first explanation of something takes 10–40 seconds while the agent reads your code. After that it's cached and instant for everyone.

Those four lines are all you need to start. **Every other option, with its default and what it does, is in [docs/configuration.md](docs/configuration.md).** To publish the config file: `php artisan vendor:publish --tag=alfred-config`. To check your setup at any time: `php artisan alfred:doctor`.

## Example setups

Copy the block that matches your situation into `.env`, then run `php artisan optimize:clear`.

### 1. Local development: everything on

You are a developer trying things on your own machine. Explanations, changes written straight to your files (with Undo), previews and screenshots are all enabled.

```dotenv
APP_ENV=local

ALFRED_ENABLED=true
ALFRED_OPENAI_API_KEY=sk-...
ALFRED_VERIFIERS=you@example.com

ALFRED_EDIT=true
ALFRED_EDIT_ENVIRONMENTS=local
ALFRED_EDIT_MODE=write
```

Keep `npm run dev` running if your changes may touch Tailwind classes.

### 2. Staging: reviewers request changes, developers ship them

Product owners and testers use Alfred on staging. They can ask for UI changes and preview them, but nothing is ever written on the server. Each request becomes a ticket (here in Jira) with the diff and screenshots.

```dotenv
APP_ENV=staging

ALFRED_ENABLED=true
ALFRED_OPENAI_API_KEY=sk-...
ALFRED_USERS=*@example.com                 # the whole team can explain and report
ALFRED_VERIFIERS=lead@example.com,designer@example.com,qa@example.com

ALFRED_EDIT=true
ALFRED_EDIT_ENVIRONMENTS=staging
ALFRED_EDIT_MODE=propose
ALFRED_EDIT_PREVIEW=true

ALFRED_TICKETS=jira
ALFRED_TICKET_LABELS=alfred,ui-change
ALFRED_JIRA_URL=https://your-company.atlassian.net
ALFRED_JIRA_EMAIL=bot@example.com
ALFRED_JIRA_TOKEN=...
ALFRED_JIRA_PROJECT=PROJ

# Optional: also on the login / sign-up pages, for testers who open the access link.
# Staging/dev only, never production.
ALFRED_GUESTS=true
ALFRED_ACCESS_KEY=a-long-random-string
```

The flow:
1. A reviewer clicks an element, then **Change this…**, and describes the change.
2. They click **Preview on this page** to check it.
3. They click **Prepare ticket** and add a screenshot of the current view.
4. A developer applies the patch from the ticket and ships it as usual.

### 3. Production: explanations only

Staff can understand the numbers on your dashboards. No code changes are possible, the AI never sees page data, and only the verifiers (here, one analyst) can open the records behind a value.

```dotenv
APP_ENV=production

ALFRED_ENABLED=true
ALFRED_OPENAI_API_KEY=sk-...
ALFRED_PRIVACY=code_only
ALFRED_USERS=ops@example.com,finance@example.com
ALFRED_VERIFIERS=analyst@example.com
ALFRED_RATE_LIMIT=4

# ALFRED_EDIT and ALFRED_GUESTS stay off (the defaults)
ALFRED_SCREENSHOTS=false
```

Only the people listed see the button. To use roles instead of emails, define the `useAlfred` gate (see [Who can see Alfred](#who-can-see-alfred)).

### 4. Explanations in another language, for a specific audience

```dotenv
ALFRED_LOCALE=de
ALFRED_AUDIENCE="a store manager who knows the business but not the code"
```

### 5. Claude, Gemini, Ollama or a different OpenAI model

```dotenv
ALFRED_OPENAI_MODEL=gpt-5                 # a larger OpenAI model
ALFRED_OPENAI_REASONING_EFFORT=medium
```

Any provider [LarAgent](https://laragent.ai) supports works too (`ALFRED_PROVIDER=laragent`). Setup, per-provider notes, gateways, usage reports and daily budgets: [docs/ai-providers.md](docs/ai-providers.md).

## Customising in code

Most projects only need `.env`. For the rest, publish the config file:

```bash
php artisan vendor:publish --tag=alfred-config
```

### Who can do what

Define any of the gates in a service provider, for example `AppServiceProvider::boot()`. Your definitions take priority over Alfred's defaults.

```php
use Illuminate\Support\Facades\Gate;

// A boolean column on users
Gate::define('useAlfred', fn ($user) => $user->is_staff);

// spatie/laravel-permission roles
Gate::define('verifyAlfred', fn ($user) => $user->hasRole('admin'));
Gate::define('editAlfred', fn ($user) => $user->hasAnyRole(['developer', 'designer']));

// Filament Shield's super admin
Gate::define('viewAlfredRecords', fn ($user) => $user->hasRole(config('filament-shield.super_admin.name')));

// Everyone may edit locally, nobody elsewhere
Gate::define('editAlfred', fn ($user) => app()->isLocal());
```

If you define `verifyAlfred` yourself, `ALFRED_VERIFIERS` is no longer used.

### Which code the agent may read and change

```php
// config/alfred.php

// Folders the agent may read (relative to your project root)
'paths' => ['app', 'resources/views', 'routes', 'database/migrations', 'src', 'modules'],

// Never readable, even inside those folders
'deny' => ['.env*', '*.pem', '*.key', '*secret*', '*credential*', 'storage/*', 'vendor/*', 'node_modules/*', '.git/*', 'app/Billing/*'],

'edit' => [
    // Folders "Change this" may modify (keep this narrow)
    'paths' => ['app/Filament', 'resources/views'],
    // ...
],
```

### Extra columns to hide in "Show the records behind this"

```php
'drilldown' => [
    'enabled' => true,
    'max_rows' => 100,
    'hidden_columns' => ['password', 'remember_token', 'api_token', 'iban', 'date_of_birth', 'national_id'],
],
```

### Your own name, button and colour

People see "Alfred" and a **?** button by default. You can rename it, give the button an image and change the accent colour:

```dotenv
ALFRED_NAME="Ask Ops"                    # used in the panel, messages and tickets
ALFRED_ICON=/images/ask-ops.png          # an image in public/, a full URL, or a data: URI
ALFRED_COLOR="#0f766e"                   # any CSS colour: #hex, rgb(), hsl(), oklch() or a name
```

`ALFRED_ICON` can also be a short text or an emoji instead of an image, for example `ALFRED_ICON=🤖` or `ALFRED_ICON=AI` (up to 3 characters). Images are shown in a 44 px circle, so a square image works best. If the image can't be loaded, the button falls back to **?**. Text on the accent colour switches between black and white automatically for `#hex` colours.

### Placing the button yourself, or not at all on some pages

```php
// config/alfred.php: pages where the button never appears
'except' => ['livewire/*', 'horizon/*', 'telescope/*', 'checkout/*', 'admin/settings*'],
```

Or turn off automatic injection with `ALFRED_INJECT=false` and add the directive to the layouts where you want it:

```blade
    ...
    @alfred
</body>
```

### Users who sign in with a different guard

Alfred looks for the signed-in user on your default guard. If people sign in through other guards too (an admin panel, a merchant or tenant panel, a customer portal), list them, in the order to check:

```dotenv
ALFRED_GUARDS=web,tenant
```

That's all: no middleware changes. Each guard's users are kept apart, so user #5 of one panel is never mistaken for user #5 of another. `ALFRED_USERS`, `ALFRED_VERIFIERS` and your gates apply to all of them.

### Multi-tenant apps

Alfred doesn't depend on any tenancy package. It has built-in support for the two most common ones and detects whichever you have installed:

| package | versions | detected by | central connection taken from |
|---|---|---|---|
| [stancl/tenancy](https://tenancyforlaravel.com) | 3.x | the `tenancy()` helper | `tenancy.database.central_connection` |
| [spatie/laravel-multitenancy](https://github.com/spatie/laravel-multitenancy) | 3.x, 4.x | `Spatie\Multitenancy\Models\Tenant` | `multitenancy.landlord_database_connection_name` |
| anything else | – | your adapter (see below) | your adapter |

If both are installed, stancl/tenancy wins. To pick one yourself, set `ALFRED_TENANCY=stancl` or `ALFRED_TENANCY=spatie`. Single-database tenancy (a `tenant_id` column, e.g. stancl's single-database mode or a hand-rolled scope) works too: Alfred only needs to know the current tenant.

With a tenancy package in place:

- **Alfred's tables stay on your central database.** Alfred uses the package's central (landlord) connection. Run `php artisan migrate` as usual; nothing goes into tenant databases. Set `ALFRED_DB_CONNECTION` only to choose a different connection.
- **Alfred works in the tenant you are looking at.** The page carries a signed reference to its tenant, and Alfred's own requests switch into that tenant before anything else runs. Users, gates, cache and files then resolve exactly as they did on the page. The reference is signed with your `APP_KEY`, only valid on that domain, and expires, so nobody can edit it to reach another tenant.
- **Nothing crosses tenants.** Explanations, their history, follow-ups, changes, requests, tickets, page recordings and security records each belong to one tenant, and every query is scoped to it. One tenant can't see, use or even detect another's, by id or otherwise, and explanations are never shared between tenants.
- **Each tenant's people are separate.** A user is identified by guard, tenant and id, so user #5 of one tenant is never user #5 of another.
- **Tenant pages must say which tenant they're in.** On a tenant's domain, Alfred's requests without a valid tenant reference are refused rather than run in the central context.

Typical stancl setup with a separate panel for tenant users:

```dotenv
ALFRED_GUARDS=web,tenant
```

Other tenancy packages: implement `Atoria\Alfred\Tenancy\TenancyAdapter` (six small methods: `available`, `current`, `initialize`, `end`, `centralConnection`, `isTenantRequest`) and set `ALFRED_TENANCY=App\Support\AlfredTenancy`. Use `ALFRED_TENANCY=none` to turn tenancy support off.

### Keeping page recordings in Redis

```dotenv
ALFRED_CACHE_STORE=redis
ALFRED_CONTEXT_TTL=7200
```

### Giving an element a fixed identity

When you want an explanation to survive layout and wording changes, give the element a key. The same key shares one explanation on that page (route), however the labels around it change:

```blade
<div class="stat">
    <span>Conversion rate</span>
    <strong data-explain="conversion-rate">{{ $conversionRate }}%</strong>
</div>
```

In Filament, add it through `extraAttributes()`:

```php
Stat::make('Conversion rate', $rate.'%')
    ->extraAttributes(['data-explain' => 'conversion-rate']);
```

## Explaining values

### How it works

1. **Alfred records the page.** For users allowed to use Alfred, it notes what each page did: the route and controller, the views, any Livewire components, and every SQL query together with the line of your code that ran it. Livewire requests made later (lazy widgets, filters, pagination) are added to the same page. This stays in your cache, on your server.
2. **You click an element.** The overlay picks up its labels (heading, column title, `aria-label`), the component it belongs to, and an optional `data-explain` key.
3. **The agent investigates.** The AI gets that context plus read-only tools to search and read your code, list routes and describe models. It follows the value to where it's calculated, then writes a short explanation.
4. **The answer comes in two parts.** The **Explanation** tab is for people who have never seen the code: what the value means, a plain formula, a worked example with simple numbers, the steps, where the data comes from, what's easy to misread, and the terms on screen. Class names, files, tables and SQL are not allowed there; if the AI uses them, Alfred sends the answer back to be rewritten. The **For developers** tab has the code path: entry point, flow with `file:line`, queries, caching and edge cases. Only people allowed by `viewAlfredCode` see it.
5. **The answer is cached and kept current.** There is one explanation per "thing on a page" (for example, a table column), shared by every user and every row (within a tenant). Alfred remembers which files it was based on. If they change, the explanation is regenerated, or flagged as "may be out of date" if someone had verified it.

Only what identifies the element (its key or labels, column, kind and components) goes into the AI request, and all of it is part of the cache key. So nobody can slip instructions into a shared explanation through the page: the same element always gets the same explanation. Who may create the explanations everyone else sees is a gate of its own, `contributeAlfredExplanations` (by default: every signed-in user who may use Alfred). Others still get an answer, but it's private to them ("Only you see this").

### The panel

The panel opens on the side of the page. Drag its inner edge to make it wider (double-click: wide / normal). The header buttons let you:
- choose **Side by side**, so the page makes room instead of being covered;
- move the panel to the other side;
- **Minimize** it while it keeps working. A small button shows when the work is done.

Your choices are remembered in your browser.

### Follow-up questions

Under every explanation there's an **Ask a follow-up** box: *"Does this include cancelled shifts?"*, *"Which dates count as this week?"*. Alfred starts from what the explanation already found, so most answers take seconds. It reads more code only when it needs to.

- **Private.** A thread belongs to the person asking and expires with the page. It's never cached or shown to anyone else.
- **Two levels.** The answer is in plain words. People who can see code also get a note for developers.
- **Keep the good ones.** Verifiers can click **Add to the explanation**, which adds the answer under "Worth knowing" for everyone.
- **Limits.** Follow-ups share the per-person rate limit with new explanations, use fewer steps (`ALFRED_FOLLOWUP_MAX_STEPS`, default 8), and stop at 10 per explanation (`ALFRED_FOLLOWUP_MAX_TURNS`). `ALFRED_FOLLOWUPS=false` turns them off.

### Carrying on from an explanation

Under an explanation, **Change this** and **Report or request** carry on with the same element. There's no need to select it again, and **Back to explanation** returns to it without asking the AI again. What Alfred found comes along:
- **Change this:** the agent starts from the explanation's code path and your follow-up answers, so it finds the right code faster.
- **Report or request:** the ticket includes the explanation and your questions with their answers.

### Reviewing explanations

People listed in `ALFRED_VERIFIERS` (or allowed by the `verifyAlfred` gate) can:
- mark an explanation as verified
- edit it
- regenerate it
- see the **For developers** tab (also controlled separately by the `viewAlfredCode` gate)
- open its **History**: every generation, edit, verification, kept follow-up and restore, with who did it and when, and restore any earlier version (`ALFRED_HISTORY_KEEP` versions are kept, default 50)


### Showing the records

**Show the records behind this** re-runs the query behind a value with the current user's own filters and lists the rows. For example, a count of 37 becomes the 37 rows. It runs only on your server; the rows are never sent to the AI.

### Pinning an element (optional)

Alfred needs no markup, but you can give an element a fixed identity with `data-explain="..."`. See [Giving an element a fixed identity](#giving-an-element-a-fixed-identity).

## Changing the UI

Off by default. Enable it for the environments where you want it:

```dotenv
ALFRED_EDIT=true
ALFRED_EDIT_ENVIRONMENTS=local,staging    # APP_ENV values, or * for all
ALFRED_EDIT_MODE=                         # write | propose | empty = write on local, propose elsewhere
```

People allowed to edit (the `editAlfred` gate, by default the verifiers) get two choices after clicking an element: **How is this calculated?** and **Change this…**. They describe the change in their own words. Alfred finds the code that renders the element and proposes the smallest edit, shown as a diff.

### Two modes

| mode | what happens | use it on |
|---|---|---|
| `write` | **Apply** writes the change to the files on this server and reloads the page. **Undo** restores them. | your local machine, or a single server whose files you edit directly |
| `propose` | Nothing is written. **Download patch** gives a file you can `git apply`, or you turn it into a ticket. | staging and shared environments |

Alfred never commits or deploys. What you do with the change is up to you.

In a multi-tenant app every tenant runs the same code, so on a tenant's pages `write` becomes `propose`: someone in one tenant can't change the app for all the others. On your own machine, `ALFRED_EDIT_WRITE_IN_TENANTS=true` allows writing there too.

### Preview on this page

In either mode, the person who asked can click **Preview on this page**. The page reloads with the change rendered for real, but only in their browser session. Everyone else, and the code on disk, stay unchanged. A bar at the top shows what's being previewed, with **Details**, **Open pull request**, **Prepare ticket** and **Stop preview**. Opening a pull request or a ticket from the bar first saves a screenshot of the previewed page, so reviewers see how it will look.

This makes a typical review flow possible on a shared environment:

1. **Request:** someone asks for a change, for example "show the status as a badge".
2. **Preview:** they preview it and check it looks right.
3. **Hand over:** from the preview bar they open a pull request, or create a ticket with "Also open a pull request" ticked. Both carry the screenshot of the preview.
4. **Ship:** a developer reviews the pull request and merges it as usual.

How it works: a signed, HttpOnly cookie identifies the change and the person it was issued to, and expires after two hours (`ALFRED_PREVIEW_MINUTES`). It only works in that person's signed-in session and while they may still edit; anyone else carrying it gets the normal page and the cookie is removed. For that user's requests only, Alfred loads the changed PHP classes and Blade views from a private temporary copy. Some changes can't be previewed. In those cases Alfred names the file and the reason rather than showing something misleading:
- CSS or JS that needs a front-end build
- files edited since the proposal
- classes your app loads before Alfred can step in

**If the change breaks the page,** for example it uses an icon that doesn't exist, the person previewing sees a recovery page instead of an error. It shows what broke and where, with a **Stop preview and reload** button. You can also stop any preview by opening `/_alfred/preview/stop`. Only the person previewing is ever affected.

Preview is turned off automatically on long-running servers such as Laravel Octane, where a swapped class could leak to other users.

### Inertia, Vue, React and Svelte

On Inertia and other JavaScript front-ends the markup lives in components under `resources/js`, not in Blade. Alfred handles that:

- **It knows which component you clicked.** The overlay reads the Inertia page name and, in development builds, the source file of the Vue, React (18 and older) or Svelte component that rendered the element and of each parent component. The agent starts from that file. In production builds only the page and the component names are known, and the agent searches for the rest.
- **It changes the component itself**: the template and its own styles (`<style scoped>`, CSS modules, utility classes). It never falls back to global `!important` rules in a layout. If it can't find the component, it says which file it would need instead of guessing.
- **Front-end changes need a build, so preview can't show them.** The change view says so. In `write` mode, click **Apply** with `npm run dev` running and Vite shows it straight away, and **Undo** removes it. In `propose` mode, send it as a pull request or ticket. Changes that also touch PHP or Blade can still be previewed for those parts.

Paths the browser reports are only hints: Alfred uses a file only if it exists in your readable code (`alfred.paths`). It matches by the project-relative part, so it also works when the app runs in Docker or on another machine.

### Not quite right? Refine it

Any change can be sent back with feedback: **Refine…** in the change view, or **Refine** in the preview bar. Say what's wrong or what should be different, in your own words, for example *"the tags show up as text"*, *"only when it's 0"*, *"use the warning colour"*, *"wrong column"*. Alfred gets:
- the original request;
- the previous attempt's diff;
- your feedback;
- any error the preview ran into.

It works out what went wrong and proposes a new attempt, which replaces the old proposal. An applied change stays applied, and the new attempt builds on it.

Alfred also points out common problems:
- **A preview that broke the page** is remembered. The change shows **Fix it with Alfred**.
- **HTML showing up as text** after previewing (markup put into a string that the framework escapes) gets a warning in the preview bar, with a pre-filled Refine.
- **Edits that would escape markup** (HTML inside a PHP string, or inside Blade's `{{ }}`) are sent back to the agent before you ever see them, with the framework's own way to do it, e.g. Filament's `->descriptionColor()`.

### Notes on Filament and Tailwind

Filament renders most of its UI from views inside `vendor/`, which can't be edited, so Alfred changes Filament UI through your PHP classes instead: `->badge()`, `->color('success')`, `->alignEnd()`, `->extraAttributes([...])`.

If a change adds Tailwind classes your compiled CSS doesn't contain yet, Alfred says so. Keep `npm run dev` running locally to see them.

## Tickets and screenshots

### Report or request

After clicking an element, everyone allowed to use Alfred also gets **Report or request…**. It skips the AI entirely:

1. Alfred takes a screenshot of the element straight away.
2. The person writes what's wrong or what they need, in their own words.
3. The ticket screen opens with a title (the first line), the description, the screenshot and a **Where in the code** section with only what matters for that element: the route and controller, its Livewire/Filament or front-end component and file, the views named after it (or the page's own view), and up to three lines of code that load its data. When the report started from an explanation, the code path the explanation traced replaces the raw query lines.

A developer picking up the ticket knows exactly where to start. People who can only report see their own requests, not other people's changes, and the change view shows code (diffs, files, what the agent read) only to people allowed to see code (`viewAlfredCode`). The ticket text always carries the code location, since that is what developers need. Turn it off with `ALFRED_REPORT=false`, or limit it with the `reportAlfred` gate.

### Tickets for changes

Every change has **Prepare ticket**. Alfred drafts:
- a title
- a description: who asked for what, on which page, and whether it was already applied or previewed
- page details, where it lives in the code, the files and the diff

You can edit the draft before sending.

Screenshots are included:

- **Before:** taken automatically when the change is requested, with the clicked element outlined.
- **Current view:** one click captures the page as it looks now, for example while previewing.

### Choosing where tickets go

```dotenv
ALFRED_TICKETS=jira                  # or github, gitlab, linear, trello, clickup, asana; several: people pick
ALFRED_TICKET_LABELS=alfred,ui-change
```

Jira, GitHub Issues, GitLab, Linear, Trello, ClickUp and Asana are built in, and you can add your own. Settings for each: [docs/trackers.md](docs/trackers.md). With no tracker set up, the ticket screen offers **Copy ticket text** (Markdown) and screenshot downloads. A change gets at most one ticket, even if two people press **Create** at once.

Screenshots are taken in the browser with the bundled [html-to-image](https://github.com/bubkoo/html-to-image) 1.11.11 (MIT) and stored on your server.

## Pull requests

Any change with code can become a **draft pull request** on GitHub, GitLab or Bitbucket (or your own git host), for a developer to review: from the change, from the preview bar, or together with its ticket (linked both ways). Alfred reads each file from the base branch through the provider's API, re-applies the edits exactly as preview did, and commits them on a new branch. Nothing is written on the server and git isn't needed there, so it works on staging and on platforms like Laravel Cloud.

```dotenv
ALFRED_PULL_REQUESTS=github
ALFRED_GITHUB_TOKEN=github_pat_...    # Contents + Pull requests: Read and write
ALFRED_GITHUB_REPO=acme/app
```

Everything else (GitLab, Bitbucket, base branch, labels, linking with tickets, your own host): [docs/pull-requests.md](docs/pull-requests.md).

## Security and privacy

### What is sent to the AI

`ALFRED_PRIVACY` controls what may leave your server:

| mode | sent to the AI provider |
|---|---|
| `code_only` *(default)* | Your source code, SQL **templates** (with `?` placeholders), route, view and component names, and labels with digits masked. Never the values shown on the page, never query parameters. |
| `redacted` | As above, plus the clicked element's text with emails, phone numbers and numbers masked. |
| `full` | Everything, including raw text and query parameters. Only for local development with fake data. |

Requests to OpenAI use `store: false`, so OpenAI keeps no conversation state between steps. Anything that looks like a credential (API keys, tokens, private keys, passwords in code) is replaced with `[secret removed]` before file contents reach the AI, and again in what the AI writes back. Recorded page URLs never keep query parameters such as `token`, `signature` or `password`.

### Requests that try to reach someone else's data

Everything people type for the AI (follow-up questions, change requests, Refine feedback) is checked first. Asking for another tenant's or another person's data or view, for secrets or credentials, or trying to override Alfred's instructions is refused before any AI call, and the agents are told the same rules. Each refusal is:
- recorded in the `alfred_security_events` table (who, where, what category, an excerpt, a hashed IP, the tenant);
- logged (`ALFRED_SECURITY_LOG_CHANNEL`);
- dispatched as an `Atoria\Alfred\Events\SuspiciousRequestBlocked` event, so you can alert on it.

Turn it off with `ALFRED_SCREENING=false`.

### What the agent can read and change

- **Read:** only files under `alfred.paths` (default `app`, `resources/views`, `resources/js`, `resources/css`, `routes`, `database/migrations`, `src`), plus the folders in `alfred.edit.paths`.
- **Change:** only files under `alfred.edit.paths` (default `app`, `resources/views`, `resources/css`, `resources/js`).
- **Never:** anything matching `alfred.deny` (`.env*`, keys and certificates, `*secret*`, `*credential*`, `*password*`, `auth.json`, `storage`, `vendor`, `.git`), matched case-insensitively. `config/` is excluded by default in case secrets are hard-coded there. Paths that escape the project, including through `..` or symlinks, are blocked.
- **Never changed, though readable:** `alfred.edit.deny` (service providers, policies, middleware, the console and HTTP kernels, exception handlers, jobs, listeners and observers), so a UI change can't touch how your app authorises or runs things.

### Safeguards for changes

- Every edit is an exact search-and-replace on a file the agent has read. PHP and Blade files must still parse, or the agent has to fix the edit before anyone sees it.
- **Apply** refuses if the file changed since the proposal. **Undo** refuses if the file changed after applying, so later work is never overwritten.
- Writes are atomic, and a failure part-way through rolls back the files already written.
- Every change keeps a trace of what the agent tried ("What Alfred tried"), including failed attempts.
- Preview, apply and pull requests turn the edits into files the same way, so what was previewed is exactly what gets applied or opened.
- Preview runs the proposed code for the person previewing, so it is limited to users who may edit, in their own signed-in session, and switches itself off. Turn it off with `ALFRED_EDIT_PREVIEW=false`.

### Records and screenshots

"Show the records behind this" runs read-only and always masks columns your models hide (`$hidden`), columns that look sensitive, and `drilldown.hidden_columns`.

Screenshots show whatever is on screen, which may include real data. Set `ALFRED_SCREENSHOTS=false` if that shouldn't be stored or sent to your tracker.

See [SECURITY.md](SECURITY.md) to report a vulnerability.

## Permissions

### Who can see Alfred

Alfred is **off for everyone** until you allow people in. There are two ways:

**1. By email, in `.env`.** Exact emails or patterns, comma-separated, case-insensitive:

```dotenv
ALFRED_USERS=ana@acme.com,*@acme-support.com     # can see Alfred, get explanations and report
ALFRED_VERIFIERS=lead@acme.com,*@acme-dev.com    # all of the above, plus verify, developer tab, records, changes
```

Verifiers can always see Alfred, so you don't need to list them twice. If both lists are empty, nobody sees it.

**2. By your own rule, in code.** Define the `useAlfred` gate and it replaces `ALFRED_USERS`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('useAlfred', fn ($user) => $user->hasAnyRole(['admin', 'manager', 'qa']));
```

People who aren't allowed see nothing: no **?** button, no Alfred script in the page, and every Alfred endpoint answers 403.

### Signed-out visitors (login pages, public pages)

> [!WARNING]
> **For local, staging and QA only. Don't turn this on in production.** On a public site, anyone who has the access link could spend your AI credits and read explanations of your code. That's why Alfred refuses guests in production unless you list `production` yourself. If you do, it logs a warning every hour.

By default Alfred only appears for signed-in people you've allowed. To use it on pages where nobody is signed in, for example to report a problem with the sign-up form:

```dotenv
ALFRED_GUESTS=true
ALFRED_ACCESS_KEY=a-long-random-string     # e.g. php -r "echo bin2hex(random_bytes(24));"
```

Then open this link once in each browser that should have it:

```
https://staging.example.com/_alfred/access?key=a-long-random-string
```

It leaves a signed cookie in that browser for 30 days (`ALFRED_ACCESS_DAYS`) and takes you back to the home page. Add `&to=/login` to land somewhere else. `/_alfred/access/forget` removes it again. To lock every browser out at once, change `ALFRED_ACCESS_KEY`.

- **On local** (`APP_ENV=local`) with no key set, guests just get it. No link needed.
- **Without a key, nowhere else.** On staging, guests need `ALFRED_ACCESS_KEY` and the link.
- **Where it's allowed:** `ALFRED_GUESTS_ENVIRONMENTS`, default `local,development,dev,staging,testing,qa`. Production is never in the default.
- **What guests can do:** **How is this calculated?** and **Report or request…** (turn the latter off with `ALFRED_GUESTS_REPORT=false`). Their explanations are private to them, and their reports wait for a signed-in person to send them to the tracker, unless you set `ALFRED_GUESTS_TICKETS=true`. Never verify, change code, see the developer tab or open records. Those always need a signed-in verifier.
- **Limits:** guests are rate-limited per IP as well as per browser (`ALFRED_GUESTS_RATE_LIMIT`, new explanations per minute, default 3; reports 5 per minute), so clearing cookies doesn't get them a fresh budget. Cached explanations are free and unlimited.
- **Signed-in users who aren't on your lists still see nothing.** The access link only covers visitors who aren't signed in.

Requests from guests show up as "A signed-out visitor" in tickets.

### All the gates

Alfred uses seven Laravel gates. If you define a gate with the same name in your own service provider, yours is used instead:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('useAlfred', fn ($user) => $user->is_staff);
Gate::define('verifyAlfred', fn ($user) => $user->is_admin);
Gate::define('viewAlfredRecords', fn ($user) => $user->is_admin);
Gate::define('editAlfred', fn ($user) => $user->hasRole('developer'));
Gate::define('viewAlfredCode', fn ($user) => $user->hasRole('developer'));
Gate::define('reportAlfred', fn ($user) => $user->is_staff);
Gate::define('contributeAlfredExplanations', fn ($user) => $user->hasRole('analyst'));
```

| gate | default | allows |
|---|---|---|
| `useAlfred` | emails in `ALFRED_USERS` or `ALFRED_VERIFIERS` | seeing Alfred at all: the **?** button and explanations |
| `verifyAlfred` | emails in `ALFRED_VERIFIERS` | verifying, editing and regenerating explanations |
| `viewAlfredCode` | same as `verifyAlfred` | the **For developers** tab of explanations |
| `viewAlfredRecords` | same as `verifyAlfred` | "Show the records behind this" |
| `editAlfred` | same as `verifyAlfred` | changing the UI, preview, tickets and pull requests (when enabled for the environment) |
| `reportAlfred` | same as `useAlfred` | **Report or request…** and tickets for your own requests |
| `contributeAlfredExplanations` | signed-in users who pass `useAlfred` | creating the shared explanations everyone sees; others get private ones |

`viewAlfredRecords` is restricted by default because it re-runs the page's query as `SELECT *`, which can reveal columns the page itself doesn't show, for example the email addresses behind a user count. Columns that look sensitive (`password`, `*token*`, `*secret*`, plus `drilldown.hidden_columns`) are always masked.

## Configuration

The settings you'll use most are in the examples above. Every `.env` option, the commands (`alfred:doctor`, `alfred:usage`, `alfred:clear`) and pruning old records: [docs/configuration.md](docs/configuration.md).

### Events

Listen to these in your app, for notifications, audit logs or analytics. All are in `Atoria\Alfred\Events`:

| event | when |
|---|---|
| `ExplanationGenerated` | the AI generated or regenerated an explanation |
| `ExplanationVerified` | a verifier approved or edited one |
| `FollowUpAnswered` | someone got an answer to a follow-up question |
| `ChangeProposed`, `ChangeApplied`, `ChangeReverted` | a change was proposed, written, or undone |
| `TicketCreated`, `PullRequestOpened` | a ticket or pull request was created |
| `SuspiciousRequestBlocked` | a request was refused (see [Security](#requests-that-try-to-reach-someone-elses-data)) |

## Limitations

- **Custom auth guards.** Alfred runs as global middleware, so it works with any middleware stack, including Filament panels. If users sign in with a guard other than the default, list it in `ALFRED_GUARDS`.
- **Tenancy packages** other than stancl/tenancy and spatie/laravel-multitenancy need a small adapter (see [Multi-tenant apps](#multi-tenant-apps)).
- **Inertia:** the first page load is recorded, but client-side navigation isn't yet, so reload the page before using Alfred on it. Front-end changes can't be previewed (they need a Vite build); see [Inertia, Vue, React and Svelte](#inertia-vue-react-and-svelte).
- **React 19** no longer exposes component source files in the browser, so Alfred finds the file from component names and the element's text instead.
- **Values computed in the browser**, such as JavaScript charts fed by an API, are explained through the API route when the agent can find it. Server-rendered values give the best results.
- **Writing changes on deployed environments:** platforms that build from git on every deploy (Laravel Cloud, Vapor, Railway, Kubernetes, zero-downtime Forge…) discard files written on the server, and with several instances only one gets the change. Use `propose` mode there.
- **Long requests:** a new explanation or change takes 20–90 seconds, longer than many load balancers allow. Alfred answers at once and finishes the work right after the response, and the overlay picks up the result, so you won't see a 504. Each person can have up to three running at once (`ALFRED_ASYNC_MAX_RUNNING`). On several servers, use a shared cache (`ALFRED_CACHE_STORE`: database or redis, not file) so any server can hand the result back.
- **Cost:** a new explanation or change usually takes 5–15 AI calls, typically a few cents with `gpt-5-mini`. Cached explanations cost nothing. `php artisan alfred:usage` shows what was spent, and daily budgets can cap it ([docs/ai-providers.md](docs/ai-providers.md#usage-and-budgets)).
- **Overhead:** while enabled, Alfred captures a short stack trace per SQL query (up to 300 per page), much like Laravel Debugbar. Visitors without a session aren't recorded, and recording stops once a signed-in user turns out not to be allowed.

## Upgrading and versioning

Alfred follows [semantic versioning](https://semver.org/). See [CHANGELOG.md](CHANGELOG.md) for what changed in each release.

```bash
composer update atoria/alfred
php artisan migrate
```

Within a major version (1.x) updates never break your app: minor versions add features, patch versions fix bugs. Breaking changes only come in a new major version, with upgrade notes. New features that change behaviour stay off until you enable them.

## Contributing

Issues and pull requests are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

```bash
git clone https://github.com/Atoria/Alfred.git
cd Alfred
composer install
composer test
```

The test suite uses a scripted fake AI client, so it runs offline and costs nothing. When you add a feature, add a test next to the existing ones in `tests/Feature`.

## License

MIT © Andria Toria. See [LICENSE](LICENSE).
