# Ticket trackers

Where **Prepare ticket** and **Report or request** send tickets, and how to set up each tracker. See the [README](../README.md#tickets-and-screenshots) for what a ticket contains.

## Choosing where tickets go

List the trackers you want with `ALFRED_TICKETS`, then add the settings for each one. Only trackers whose settings are complete are offered.

```dotenv
ALFRED_TICKETS=jira                  # one tracker
ALFRED_TICKETS=github,trello         # several: the first is the default, people pick in a "Send to" list
ALFRED_TICKET_LABELS=alfred,ui-change    # labels/tags added to every ticket, where the tracker supports them
ALFRED_TICKET_INCLUDE_DIFF=true      # page details, code location and diff in the ticket (people can untick it)
```

With no tracker set up, the ticket screen offers **Copy ticket text** (Markdown) and screenshot downloads, so you can paste it anywhere.

| tracker | what you get | screenshots |
|---|---|---|
| **Jira** (Cloud) | an issue with a formatted description | attached |
| **GitHub** Issues | an issue in one repo, Markdown | linked (GitHub's API can't upload images) |
| **GitLab** issues | an issue in one project, Markdown | uploaded and shown in the description |
| **Linear** | an issue in one team, Markdown | uploaded and shown in the description |
| **Trello** | a card at the top of one list | attached |
| **ClickUp** | a task in one list, Markdown, tags | attached |
| **Asana** | a task in one project | attached |

If an upload fails, the ticket is still created and Alfred tells you which file didn't make it. Every ticket ends with "Created with Alfred from change #12", and Alfred remembers the ticket so it's never created twice, even when two people press **Create** at the same moment.

### Jira

```dotenv
ALFRED_TICKETS=jira
ALFRED_JIRA_URL=https://your-company.atlassian.net
ALFRED_JIRA_EMAIL=you@example.com         # the account the API token belongs to
ALFRED_JIRA_TOKEN=...                     # id.atlassian.com → Security → API tokens
ALFRED_JIRA_PROJECT=PROJ                  # your project key
ALFRED_JIRA_ISSUE_TYPE=Task               # optional
ALFRED_JIRA_LABELS=alfred,ui-change       # optional, instead of ALFRED_TICKET_LABELS
```

### GitHub Issues

```dotenv
ALFRED_TICKETS=github
ALFRED_GITHUB_TOKEN=github_pat_...       # fine-grained token, "Issues: Read and write" on the repo
ALFRED_GITHUB_REPO=acme/app
ALFRED_GITHUB_LABELS=bug                 # optional
ALFRED_GITHUB_API_URL=https://github.example.com/api/v3   # GitHub Enterprise Server only
```

GitHub's API can't upload images, so the issue links to the screenshots stored in your app. People need to be signed in to your app to open them.

### GitLab

```dotenv
ALFRED_TICKETS=gitlab
ALFRED_GITLAB_TOKEN=glpat-...            # personal or project access token with the "api" scope
ALFRED_GITLAB_PROJECT=acme/app           # path or numeric id
ALFRED_GITLAB_URL=https://gitlab.com     # or your self-hosted GitLab
ALFRED_GITLAB_LABELS=bug                 # optional
```

### Linear

```dotenv
ALFRED_TICKETS=linear
ALFRED_LINEAR_API_KEY=lin_api_...        # Settings → Security & access → Personal API keys
ALFRED_LINEAR_TEAM=ENG                   # team key (as in ENG-123) or team id
```

### Trello

```dotenv
ALFRED_TICKETS=trello
ALFRED_TRELLO_KEY=...                    # trello.com/power-ups/admin → your Power-Up → API key
ALFRED_TRELLO_TOKEN=...                  # the "Token" link next to the API key
ALFRED_TRELLO_LIST_ID=...                # the list new cards go to (open a card, add .json to its URL, look for idList)
ALFRED_TRELLO_LIST_NAME=Inbox            # optional, shown on the button
ALFRED_TRELLO_LABEL_IDS=...              # optional, comma-separated label ids
```

### ClickUp

```dotenv
ALFRED_TICKETS=clickup
ALFRED_CLICKUP_TOKEN=pk_...              # Settings → Apps → API token
ALFRED_CLICKUP_LIST_ID=901234567         # the number in the list's URL
ALFRED_CLICKUP_LIST_NAME=Bugs            # optional, shown on the button
ALFRED_CLICKUP_TAGS=alfred               # optional, instead of ALFRED_TICKET_LABELS
```

### Asana

```dotenv
ALFRED_TICKETS=asana
ALFRED_ASANA_TOKEN=...                   # app.asana.com/0/my-apps → personal access token
ALFRED_ASANA_PROJECT=1201234567890       # the project's number in its URL
ALFRED_ASANA_PROJECT_NAME=Bugs           # optional, shown on the button
```

### Your own tracker

Implement `Atoria\Alfred\Tickets\Trackers\Tracker` (or extend `BaseTracker`), register it in `config/alfred.php` and list it in `ALFRED_TICKETS`:

```php
// config/alfred.php
'tickets' => [
    // ...
    'custom' => [
        'youtrack' => App\Alfred\YouTrackTracker::class,
    ],
],
```

```php
use Atoria\Alfred\Tickets\Ticket;
use Atoria\Alfred\Tickets\Trackers\BaseTracker;
use Illuminate\Support\Facades\Http;

class YouTrackTracker extends BaseTracker
{
    protected array $required = ['url', 'token', 'project']; // read from config('alfred.trackers.youtrack.*')

    public function name(): string { return 'youtrack'; }
    public function label(): string { return 'YouTrack'; }
    public function target(): ?string { return $this->config('project'); }

    public function create(Ticket $ticket): array
    {
        $res = $this->call('the issue', fn () => Http::withToken($this->config('token'))
            ->post($this->config('url').'/api/issues?fields=idReadable', [
                'project' => ['shortName' => $this->config('project')],
                'summary' => $ticket->title,
                'description' => $ticket->body(),   // Markdown with details, code location and diff
            ]));

        $key = $res->json('idReadable');

        return ['key' => $key, 'url' => $this->config('url').'/issue/'.$key, 'warnings' => []];
    }
}
```

`$ticket` also has `->adf` (Jira's format), `->files` and `->images()` (the screenshots, plus the full patch when the diff is long) and `->screenshotUrls`.

Screenshots are taken in the browser with the bundled [html-to-image](https://github.com/bubkoo/html-to-image) (MIT) and stored on your server. Images from other sites that don't allow copying, such as some avatar services, are left out of the screenshot rather than breaking it.
