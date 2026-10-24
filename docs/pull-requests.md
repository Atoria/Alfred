# Pull requests

Any change with code can become a **pull request** for a developer to review, so a change asked for on staging reaches your repository without anyone copying patches around.

- On a change, click **Open pull request**.
- Or tick **Also open a pull request** when creating its ticket. The ticket is created first, so the pull request carries its key and link.

What Alfred does:

1. Reads each changed file from the base branch through the provider's API (your default branch, or `ALFRED_PR_BASE`).
2. Re-applies the change's edits to that copy. If the base branch moved on and an edit no longer fits, it stops and says which file.
3. Commits everything as one commit on a new branch, for example `alfred/proj-42-12-bold-the-orders-total`.
4. Opens a **draft** pull request describing who asked for what on which page, with the files, where it is on the page, links to the screenshots and the ticket.

Nothing is written on the server and git doesn't need to be installed there, so this works on staging in `propose` mode and on platforms like Laravel Cloud. Only people allowed to change the UI (`editAlfred`) can open pull requests, and each change gets at most one.

```dotenv
ALFRED_PULL_REQUESTS=github           # or gitlab, bitbucket; several: people pick one
ALFRED_PR_BASE=                       # empty: the repository's default branch
ALFRED_PR_DRAFT=true                  # open as drafts
ALFRED_PR_BRANCH_PREFIX=alfred/
ALFRED_PR_PATH_PREFIX=                # if the app lives in a subfolder of the repository, e.g. backend
ALFRED_PR_LABELS=alfred               # optional (GitHub and GitLab)
```

**Linking tickets and pull requests.** With a ticket, the pull request title starts with the ticket key (`PROJ-42: Bold the orders total`) and the branch contains it, which is what Jira's and Linear's GitHub/GitLab integrations use to link them. A GitHub issue gets `Closes #12` in a GitHub pull request, and the same for GitLab, so merging closes the issue.

### GitHub

Uses the same settings as GitHub Issues, so one token can do both:

```dotenv
ALFRED_PULL_REQUESTS=github
ALFRED_GITHUB_TOKEN=github_pat_...    # fine-grained, on the repo: Contents + Pull requests "Read and write" (+ Issues for tickets)
ALFRED_GITHUB_REPO=acme/app
```

Draft pull requests on private repositories need a paid GitHub plan; on a free plan Alfred opens a normal one and says so.

### GitLab

```dotenv
ALFRED_PULL_REQUESTS=gitlab
ALFRED_GITLAB_TOKEN=glpat-...         # "api" scope, Developer role or higher
ALFRED_GITLAB_PROJECT=acme/app
ALFRED_GITLAB_URL=https://gitlab.com
```

GitLab calls them merge requests; Alfred does too.

### Bitbucket Cloud

```dotenv
ALFRED_PULL_REQUESTS=bitbucket
ALFRED_BITBUCKET_REPO=acme/app        # workspace/repo
ALFRED_BITBUCKET_TOKEN=...            # repository access token (Repositories: write, Pull requests: write)
# or an Atlassian API token with your email:
# ALFRED_BITBUCKET_EMAIL=you@example.com
```

### Your own git host

Implement `Atoria\Alfred\PullRequests\Providers\GitProvider` (or extend `BaseGitProvider`), register it under `pull_requests.custom` in `config/alfred.php` and list its name in `ALFRED_PULL_REQUESTS`. Its `open()` gets a `PullRequestDraft` with the title, body, branch, commit message and the paths to change. Call `$draft->apply($path, $contentOnBaseBranch)` to get each file's new contents: it uses the same edit rules as preview and **Apply**, so the pull request contains exactly what was previewed.
