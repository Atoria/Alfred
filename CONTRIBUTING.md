# Contributing to Alfred

Thanks for helping. Bug reports, fixes, docs and features are all welcome. For anything bigger than a small fix, please open an issue first so we can agree on the approach before you spend time on it.

Security problems: see [SECURITY.md](SECURITY.md) instead of opening an issue.

## Setup

Requirements: PHP 8.2+ (8.3+ for Laravel 13 or LarAgent), Composer 2, and Node (only for `node --check` on the JS file).

```bash
git clone https://github.com/Atoria/Alfred.git
cd Alfred
composer install

vendor/bin/phpunit      # tests
composer analyse        # Larastan
composer format         # Pint (fixes files in place)
```

The test suite uses a scripted fake AI client (`tests/FakeLlm.php`), so it runs offline and costs nothing. Never add tests that call a real AI provider, tracker or Git host.

To run the LarAgent tests, which are skipped when the package isn't installed (PHP 8.3+):

```bash
composer require --dev maestroerror/laragent
vendor/bin/phpunit --filter LarAgentTest
```

Don't commit that change to `composer.json`.

To test against a specific Laravel version, as CI does:

```bash
composer require --dev --no-update "illuminate/support:^12.0" "orchestra/testbench:^10.0"
composer update
```

## Coding standards

- **Style:** [Pint](https://laravel.com/docs/pint) with the `laravel` preset (`pint.json`). Run `composer format` before pushing; CI runs `pint --test`.
- **Static analysis:** [Larastan](https://github.com/larastan/larastan) at level 5 on `src/` (`phpstan.neon.dist`). New code must not add errors. Don't add entries to `phpstan-baseline.neon` to silence new problems; the baseline is only for existing code and should shrink over time.
- `declare(strict_types=1)` is not required.
- **JavaScript:** `resources/js/alfred.js` is hand-written ES5 with no build step, so it runs in any browser the host app supports. Keep it that way: no `let`/`const`, arrow functions, modules or npm dependencies, and don't add a `package.json`. `resources/js/vendor/` holds vendored third-party code; don't edit it by hand.
- Keep optional integrations optional. Code that touches Livewire, LarAgent, `stancl/tenancy` or `spatie/laravel-multitenancy` must check the package is installed first.

## Tests

Every change needs a test: a failing test that the fix makes pass, or tests covering a new feature. Put them next to the existing ones in `tests/Feature` (or `tests/Unit` for isolated logic). Pull requests without tests are usually sent back.

## Changelog

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Add your entry under the `## [Unreleased]` heading at the top (create it if it's missing), in the right group: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` or `Security`. Write for users of the package: what they can now do or what was broken, plus any new `ALFRED_*` setting.

## Pull requests

1. Fork the repository and create a feature branch from `main` (`fix/preview-octane`, `feature/linear-labels`).
2. Make your change with tests, a changelog entry and any docs updates.
3. Open a pull request against `main` and fill in the template.
4. CI must be green: tests across PHP 8.2–8.5 and Laravel 11–13, plus the `quality` job (composer validate/audit, Larastan, Pint, JS syntax, dist contents).
5. A maintainer reviews it. Once approved, it's **squash-merged**, so the PR title becomes the commit message. Make it a clear summary.

## Versioning

Alfred follows [Semantic Versioning](https://semver.org/):

- **Patch** (`1.0.1`): bug fixes only. No new features, no new config keys with behaviour changes.
- **Minor** (`1.1.0`): backward-compatible additions. New features that change what users see or what is sent to the AI provider are **off by default**.
- **Major** (`2.0.0`): breaking changes (removed or renamed config, public API, routes, database changes that need manual steps, dropped PHP/Laravel versions), with upgrade notes.

At most one minor release per week, unless it's a security fix.

## Release process (maintainers)

1. Make sure `main` is green.
2. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD` and add a fresh empty `## [Unreleased]` above it. Merge that to `main`.
3. Tag the merge commit and push the tag:
   ```bash
   git tag vX.Y.Z
   git push origin vX.Y.Z
   ```
4. The `Release` workflow creates the GitHub Release with that version's changelog section as its notes. It fails if the section is missing.
5. Packagist updates automatically through the GitHub integration. Check that the new version appears on [packagist.org/packages/atoria/alfred](https://packagist.org/packages/atoria/alfred).

## Code of conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md). By taking part you agree to uphold it.
