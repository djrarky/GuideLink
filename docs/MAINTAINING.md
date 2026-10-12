# Maintaining GuideLink

This guide is for maintainers reviewing plugin changes, preparing releases, or
deciding whether the source/provider/country model needs to change.

Related docs:

- [Install](INSTALL.md): operator install, update, verify, and uninstall guide.
- [Configure](CONFIGURATION.md): first-run setup, profiles, actions, and runtime behavior.
- [Extend](EXTENDING.md): contributor-facing source and scraper extension steps.

## Repository Boundaries

Keep plugin changes inside this repository unless a host change is explicitly
required. The `m3u-editor` reference checkout is separate and should not be
changed by ordinary GuideLink pull requests.

Treat `m3u-editor/docs/plugin-dev.md` in the host repository as the source of
truth for plugin lifecycle, manifest, schema, permissions, and install-review
behavior. GuideLink docs may specialize that guidance for this plugin,
but should not contradict it.

The host must support dynamic plugin select options for the provider, country,
source, and scraper fields. The plugin still validates those manifest contracts
locally so stale static option lists are not reintroduced.

## Host Plugin Contract

When reviewing plugin-system changes, check GuideLink against the
m3u-editor plugin contract:

- Required plugin files stay present: `plugin.json` and the manifest
  `entrypoint`, currently `Plugin.php`.
- The manifest declares the host capabilities it uses, including `scheduled`.
- Hooks remain explicit. GuideLink subscribes to `playlist.synced`.
- Permissions match behavior: schema/data changes, queue jobs, hook
  subscriptions, scheduled runs, filesystem cache writes, and network fetches
  stay declared in `plugin.json`.
- The referenced m3u-editor host schema includes the native recovery columns
  GuideLink audits and configures:
  `channels.last_scrubber_live`,
  `channel_scrubbers.protect_failover_channels`, and
  `channel_scrubbers.rebuild_failovers_after_scan`.
- Plugin-owned tables stay under the host-required prefix
  `plugin_guidelink_`.
- Plugin-owned files and directories stay under approved host storage roots,
  currently `plugin-data/guidelink`.
- Data ownership declarations include the plugin-owned tables and storage paths
  that uninstall may preserve or purge.
- `schema.tables` declares physical tables and `schema.ui_tables` declares the
  host-rendered **Data** tab UIs; plugin PHP should not run arbitrary
  migrations.
- Manual write actions keep `"requires_confirmation": true`.
- Provider, country, source, and scraper selects stay backed by
  `options_provider`, not duplicated static `options`.
- Prefilled assignment rows stay non-destructive: clearing an assignment uses
  `delete_behavior: "clear"` plus an explicit `delete_payload`.
- Run result exports include the diagnostic `details` payload, not just the
  columns most useful for on-screen scanning.
- Native scrubber and auto-merge setup keeps failover policy explicit, including
  hidden failover preservation and rebuild-after-scan behavior.
- Reviewed install, trust, integrity verification, enable/disable, uninstall,
  reinstall, and doctor flows continue to work through the host commands.

## Source Model

The source model has three layers:

- `SourceRegistry` owns provider/source labels and PHP classes.
- `src/Sources/Countries/<Country>/country.json` owns country option identity:
  `key` is the saved value and `name` is the UI label.
- `src/Sources/Countries/<Country>/<ProviderCountry>/provider.json` owns the
  provider/source compatibility matrix for that country.

The UI uses the same model:

```text
country -> supported providers -> supported provider sources
```

Runtime execution also calls `SourceRequest::assertSupported()`, so stale saved
data or manual payloads cannot run unsupported combinations.

## Reviewing Source, Provider, And Country PRs

For a new source on an existing provider, expect:

- one `ChannelSource` under
  `src/Sources/Countries/<Country>/<ProviderCountry>/Sources/`,
- a `SourceRegistry` entry with a stable lowercase source key,
- the source key listed in each supported country's `provider.json`,
- at least one small synthetic fixture under
  `tests/Fixtures/Sources/Countries/<Country>/<ProviderCountry>/Sources/<Source>/`,
- no cached upstream pages or raw scraper responses.

For a new country, expect:

- `country.json` under `src/Sources/Countries/<Country>/`,
- a lowercase, unique `country.json` `key` and a non-empty display `name`,
- `normalization.json`, `regions.json`, and `filters.json` only when that
  country needs custom normalization, region aliases, or exclusions,
- one provider-country `provider.json` for each supported provider,
- fixtures proving every registered source/country pair can parse its source
  shape,
- parser changes if the existing `ChannelSource` is not actually compatible.

For a new provider, expect:

- provider-country code under
  `src/Sources/Countries/<Country>/<ProviderCountry>/`,
- an adapter implementing `ProviderSource`,
- one or more sources implementing `ChannelSource`,
- a `SourceRegistry` provider entry,
- provider-country support metadata and fixtures.

For a new scraper backend, expect:

- a `ScraperClient` under `src/Scrapers/Backends/`,
- a `ScraperRegistry` entry with an accurate `requires_api_key` flag,
- fake-response tests for parsing and failure behavior,
- no source PRs forced to add scraper tests when they reuse an existing backend.

## Validation Gates

Before merging ordinary source/provider/country/scraper changes, run:

```bash
php scripts/validate-plugin.php
```

If host PHP is unavailable, run the validator from the workspace Podman harness:

```bash
cd ../podman
./bin/compose exec m3u-editor-plugin-dev \
  php /var/www/html/plugins/guidelink/scripts/validate-plugin.php
```

Run focused tests for touched areas:

```bash
cd ../podman
./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests/Unit/Sources/ChannelSourceFixtureTest.php

./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests/Feature/Contracts/ManifestRegistryTest.php
```

For scraper changes, also run:

```bash
cd ../podman
./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests/Feature/Scrapers/ScraperClientTest.php
```

For broader behavior or release candidates, run the full plugin suite:

```bash
cd ../podman
./bin/compose --profile test build m3u-editor-plugin-test
./bin/compose --profile test run --rm \
  m3u-editor-plugin-test \
  /var/www/html/plugins/guidelink/tests
```

Latest workspace run on 2026-10-12: the Podman validator passed and the full
container suite passed with 88 tests and 553 assertions in the existing test image. Rebuild the image whenever the m3u-editor reference checkout changes
migrations or native model schema; a stale image can fail with missing host
columns even when the working tree contains the migrations.

## CI Boundary

CI runs the plugin-owned checks that can stay scoped to this repository:

- `CI / Plugin Contract` installs PHP, lints plugin PHP files, and runs the
  standalone plugin validator, packaging tests, and an archive build.
- `CI / Plugin Code Quality` checks out m3u-editor, installs host dependencies,
  and runs Pint's Laravel preset against `plugins/guidelink`, without the host's
  Blade formatter.
- `CI / Plugin Host Tests` boots m3u-editor with PostgreSQL and Redis services,
  runs migrations, discovers and validates GuideLink, then runs
  `php artisan test plugins/guidelink/tests`.

The full m3u-editor suite, Docker build validation, and dependency/security
scans remain upstream host-app gates. GuideLink CI can be pointed at a
specific host repository, server, or ref through the workflow inputs or the
`M3U_EDITOR_REPOSITORY`, `M3U_EDITOR_SERVER_URL`, and `M3U_EDITOR_REF`
repository variables.

`.github/scripts/install-ci-php.sh` prepares the CI runner with PHP 8.4 and the
extensions requested by each job. Host integration jobs also request Composer.
The script supports Ubuntu and Debian runners; it is not a GuideLink installer,
does not run on users' servers, and is excluded from release ZIPs. The workflows
currently use this script, not `shivammathur/setup-php`.

Local validation has passed. GitHub [validation](https://github.com/djrarky/GuideLink/actions/runs/38306481838)
and [draft-release packaging](https://github.com/djrarky/GuideLink/actions/runs/38306905129)
also passed for the initial `v0.1.0` source. Subsequent changes need their own
CI run and release assets. Browser first-run testing and a minimum supported
host release remain to be verified before publishing.

## Release Checklist

- Update `version` in `plugin.json`.
- Run `php scripts/validate-plugin.php` or the container equivalent.
- Run the focused tests for changed areas.
- Run the full plugin test directory for release candidates.
- Rebuild the Podman test image first when host migrations or native schema have
  changed.
- Confirm write actions still declare `"requires_confirmation": true`.
- Confirm generated archives, cached source pages, local secrets, and raw
  scraper responses are not committed.
- Confirm `README.md`, `CONTRIBUTING.md`, `docs/INSTALL.md`,
  `docs/CONFIGURATION.md`, `docs/EXTENDING.md`, and this file still match the
  current source layout and install flow.

## GitHub Release Workflow

The repository root on GitHub must be the contents of `plugin-port/guidelink`,
so `.github/workflows`, `plugin.json`, and `Plugin.php` are at the top level.
Do not push the parent development workspace or the m3u-editor reference checkout.

The workflows separate everyday pushes from releases:

- Branch pushes and pull requests run **Validate Plugin**.
- Pushing a tag matching `v[0-9]*` runs **Release**, which calls the same validation
  workflow, including formatting and the full host plugin tests.
- After validation succeeds, the release job checks that the tag is exactly `v`
  plus the version in `plugin.json`, packages that tagged commit, verifies
  checksums, and creates a **draft GitHub release** with the three assets attached.
- Review and publish the draft yourself. An ordinary push never publishes a release.

This follows the tag-to-draft pattern used by
[netbird-unifi](https://github.com/djrarky/netbird-unifi/blob/main/.github/workflows/release.yml).
GitHub supplies the release job's token; a separate publishing token is not needed.
Repository or organization policy must allow GitHub Actions and its requested
`contents: write` permission for creating drafts.

Before the first release, set the repository Actions variable `M3U_EDITOR_REF`
to the host commit or tag you intend to support and test. Without it, CI uses the
host's `dev` branch. The optional `M3U_EDITOR_TOKEN` Actions secret is only needed
when that host checkout requires authentication.

### First Push

Create an empty GitHub repository (without an initial README or license).
For a new GuideLink checkout with no Git history, run from the plugin directory,
replacing `OWNER` with your GitHub account or organization:

```bash
cd plugin-port/guidelink
git init -b main
git add .
git diff --cached --stat
git commit -m "Initial GuideLink source"
git remote add origin git@github.com:OWNER/guidelink.git
git push -u origin main
```

If you have an existing GuideLink Git checkout, keep its history and remote;
commit and push there instead of initializing another repository. Generated ZIPs
and `dist/` are ignored.

### Create A Release

Update `plugin.json`, commit the changes, and push `main`.
For the initial `0.1.0` version:

```bash
git add plugin.json
git commit -m "Prepare v0.1.0"
git push origin main
```

If the desired version is already committed, skip that commit.
Wait for **Validate Plugin** to pass, then tag the tested commit:

```bash
git tag -a v0.1.0 -m "GuideLink v0.1.0"
git push origin v0.1.0
```

Follow **Actions → Release**. Once it completes, open **Releases**, edit the draft,
review the generated notes, and select **Publish release** after completing the checks below. Users download the attached
`guidelink.zip`. There is no need to upload a locally built ZIP or manually create
another release. For the next release, use a new version and matching tag.

If validation fails, no draft is created. For an infrastructure failure, fix the
repository settings and rerun the failed jobs. For a source fix, commit it and use
a new version/tag; existing tags keep identifying the code originally tested.

### Commit Messages And Release Notes

`scripts/release-notes.py` generates the draft body from Git history. Use clear
commit subjects with these prefixes; an optional scope is supported:

```text
feat(aliases): Share aliases across custom sets
fix: Preserve timeshift matching
docs: Explain plugin updates
```

`feat`, `fix`, `perf`, `refactor`, `docs`, and `chore` become Features, Bug Fixes,
Performance, Refactoring, Documentation, and Maintenance sections. Other subjects
appear under Other Changes. Add `!` before the colon or a `BREAKING CHANGE:`
paragraph in the commit body to flag a breaking change. The generated notes list
its subject under Breaking Changes; add migration details when reviewing the draft.

The range starts after the closest previous version tag on first-parent history
and ends at the released tag. Stable releases skip prerelease tags when choosing
that starting point, so changes introduced in release candidates remain included.
The initial release includes all reachable non-merge commits, including the root
commit. Merge commits, version-only subjects, and repeated messages within the
range are omitted. Commits merged through branches are included. Each entry links
to its commit; later releases also link to the full comparison.

These notes include direct commits as well as PR commits. With squash merges, use
a descriptive prefixed PR title, since it becomes the commit subject. A first
release pushed as one initial commit will have only that commit in its notes;
expand the draft's feature and compatibility description before publishing.

Release notes live in GitHub Releases. There is no separately maintained changelog.
The workflow checks out full history and passes the generated Markdown to
`gh release create --notes-file`; it does not use GitHub's PR-only note generator.

## Prepare Release Assets

The GitHub workflow builds these automatically. Use the following only when you
need a local archive for testing; there is no committed release directory.

Python 3 is required only for packaging, not for running the plugin.

```bash
python3 -m unittest discover -s tests/release -v
python3 scripts/package-release.py
cd dist/v0.1.0
sha256sum -c SHA256SUMS
```

The version comes from `plugin.json`. The output contains `guidelink.zip`,
`SHA256SUMS`, and `release.json` with a SHA-256 inventory of every packaged file.
The archive has one `guidelink/` root and includes runtime source, documentation,
license, validation/packaging scripts, tests, and synthetic fixtures. Packaging
uses an explicit file/type allowlist, rejects symlinks, excludes hidden files,
and omits Git metadata, CI configuration, dependencies, caches, and local reports.
ZIP ordering, timestamps, and permissions are fixed for repeatable builds.
Review the file inventory before publishing; an allowlist cannot detect secrets
accidentally embedded in source code.

The packager reads the current working tree, including uncommitted edits. Before
publishing, commit the reviewed source and rebuild from that exact commit. Verify
the same archive is installed and tested; do not use `git archive HEAD` while
release edits are still uncommitted.

The GitHub workflow attaches all three files to a draft release. Publish after
these checks are recorded:

- Standalone validation, PHP lint, PHP formatting, and plugin tests pass.
- The packaged archive passes reviewed install, trust, integrity, and health checks.
- Browser first-run checks pass: profile creation, playlist assignment, Preview
  Sync, Results inspection, and confirmed Run Sync on a test playlist.
- Record the tested host version/commit, or the exact local image ID if the image
  does not provide source provenance. Do not infer a minimum supported version.
- Verify the remote CI run against the selected host ref. Set `M3U_EDITOR_REF`
  to a known tag/commit or supply `m3u_editor_ref` when dispatching the workflow.

The packaging script creates local assets only; it does not commit, tag, push,
or publish a release.
