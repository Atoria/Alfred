# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 1.x | Yes |

## Reporting a vulnerability

Please **do not** open a public issue, discussion or pull request for a security problem.

Report it privately in one of these ways:

- **GitHub private vulnerability reporting:** on [github.com/Atoria/Alfred](https://github.com/Atoria/Alfred), open the **Security** tab and click **Report a vulnerability**.
- **Email:** [toria.andria@gmail.com](mailto:toria.andria@gmail.com), with "Alfred security" in the subject.

Include the Alfred, Laravel and PHP versions, the relevant `ALFRED_*` settings, and steps or a proof of concept. Leave out real API keys and customer data.

What to expect:

- **Acknowledgement** within 3 business days.
- **Assessment** and a severity estimate shortly after, with questions if anything is unclear.
- **A fix for critical issues** within 14 days of confirmation; other issues in the next regular release.
- A GitHub security advisory and a CHANGELOG entry once a fix is released. Reporters are credited unless they ask not to be.

## Security model

Alfred is a developer and support tool that runs inside your application with access to your code. Know what it can do before you enable it:

- **Code leaves your server.** To explain or change something, Alfred sends source code from `alfred.paths`, SQL templates and route/view/component names to the configured AI provider (OpenAI, or whichever provider you set up through LarAgent). With `ALFRED_PRIVACY=redacted` or `full`, text and values shown on the page are sent too. Files matching `alfred.deny` (`.env*`, keys, `*secret*`, `*credential*`, `storage`, `vendor`, `.git`) are never read.
- **"write" mode edits files on the server.** With `ALFRED_EDIT=true` and `ALFRED_EDIT_MODE=write`, **Apply** writes AI-proposed changes to files under `alfred.edit.paths`. In `propose` mode nothing is written; the change is stored as a patch.
- **Preview runs proposed code.** **Preview on this page** executes the not-yet-applied change for the person previewing, in their session only. It is limited to users who pass `editAlfred`.
- **It records request context.** For users who pass `useAlfred`, Alfred records each page's route, controller, views, components and SQL queries (with bindings) in the cache for `ALFRED_CONTEXT_TTL` seconds. "Show the records behind this" re-runs a page's query and can reveal columns the page doesn't show.
- **Guests mode** (`ALFRED_GUESTS=true`) lets signed-out visitors with the access link get explanations and file reports, which spends your AI credits and explains your code to them. It refuses production unless you list it.
- **Tickets and pull requests** use the tracker and repository tokens you configure, and can include diffs and screenshots of the page.

## Hardening checklist

- Keep `ALFRED_EDIT` off in production. Enable it only in `local` or staging via `ALFRED_EDIT_ENVIRONMENTS`, and prefer `ALFRED_EDIT_MODE=propose` anywhere the code isn't yours to change on disk.
- Keep `ALFRED_PRIVACY=code_only` (the default) in production and anywhere with real data.
- Restrict the gates: list only the people who need it in `ALFRED_USERS` / `ALFRED_VERIFIERS`, or define `useAlfred`, `verifyAlfred`, `viewAlfredRecords`, `viewAlfredCode` and `editAlfred` yourself. Keep `viewAlfredRecords` and `editAlfred` narrow.
- Don't enable guests in production. Where you do enable them, use a long random `ALFRED_ACCESS_KEY` and rotate it to revoke access.
- Run AI work in the background (`ALFRED_ASYNC=true`, the default) on a real queue connection, not `sync`, and keep `ALFRED_RATE_LIMIT` low.
- Review `alfred.paths` and `alfred.deny` for your project. Keep `config/` and anything with hard-coded secrets out.
- Give tracker and repository tokens the smallest scope that works. Leave `ALFRED_PR_DRAFT=true` so a human reviews every pull request.
- Set `ALFRED_SCREENSHOTS=false` if screenshots of real data shouldn't reach your tracker.
- Use a dedicated, spend-limited API key for the AI provider.
