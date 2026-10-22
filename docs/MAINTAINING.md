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

## Following A Sync Through The Code

Start with [SyncPlaylistAction](../src/Actions/SyncPlaylistAction.php).
`Plugin.php` creates an action for each dispatch. `PlaylistBatchRunner` resolves
the selected assignments and calls the action once per playlist, using the same
action's `GuideLinkServices` factory throughout the batch.

| Stage | Main code | Responsibility |
| --- | --- | --- |
| Resolve inputs | `PlaylistRunResolver`, `PlaylistResolver`, `GuideMapService` | Resolve the profile, check playlist/guide access and reject ambiguous guide mappings. |
| Prepare source | `SourceCacheService`, `SourceRefreshService`, `ParsedSourceLoader`, `ProfileAliasResolver` | Validate source data, add current guide identities and apply valid profile aliases. |
| Select and match | `ChannelRepository`, `ScopeFilter`, `ChannelMatcher` | Read live channels, record exclusions and produce matching decisions without editing channels. |
| Plan changes | `WritePlanBuilder`, `GroupSyncService`, `ChannelQuarantineService` | Calculate metadata differences, managed groups, quarantine and eligible provider-group cleanup. |
| Apply native settings | `PlaylistSetupService`, `AutoMergeSetupService`, `ScrubberSetupService` | Apply profile configuration and save its audit records. Sync does not start a scan or failover rebuild. |
| Apply channel policy | `GroupSyncService`, `ChannelWriter`, `ChannelQuarantineService` | Ensure destination groups exist, update metadata, then quarantine channels and disable eligible provider groups. |
| Report | `WritePlanBuilder::summary`, `RunResultRepository` | Persist decisions and plans, and return counts, errors and cancellation status. |

Preview follows preparation and planning, previews native settings and saves
results. It may populate a missing scraper cache, but does not apply native
settings, channel metadata, group or quarantine changes. Ordinary sync reuses a
valid last-good cache even when it is old; freshness-based fetching belongs to
the refresh/setup actions. A corrupt or undersized existing cache fails sync.

Keep these ordering constraints visible when editing the action:

- Quarantine uses all matching and exclusion decisions, including channels with
  no metadata differences. A missing write-plan row does not mean preservation.
- Creating a destination group requires rebuilding the channel write plan with
  its real ID. Preview can legitimately contain a `missing_group` instead.
- Group setup failure stops channel writes. Failed or cancelled metadata writes
  block quarantine; failed or cancelled quarantine blocks provider-group cleanup.
- Provider-group cleanup rechecks current membership in the update query, so a
  newly imported or unquarantined channel prevents its group being disabled.

For matching changes, start at `ChannelMatcher::match` and `matchName`. The
ordered stages are policy: fingerprint evidence, exact/explicit aliases and
structured name rules precede fuzzy scoring. Guide-only fallback can use exact
or structured matches, but never fuzzy scoring. `MatchDecision` defines which
decisions are confirmed and which exclusions preserve a channel.

## Transactions And Partial Results

A sync has several commit boundaries. It is not one transaction around the
whole playlist or batch.

| Work | Commit boundary | Consequence of a later failure |
| --- | --- | --- |
| Native settings and their audit rows | One transaction in `SyncPlaylistAction`; playlist settings are saved together | Successful configuration remains if channel processing later fails. |
| Managed group creation/restoration/update | One transaction per group in `GroupSyncService::applyRow` | Earlier group changes remain; a failed group stops subsequent channel writes. |
| Channel metadata | One transaction per channel in `ChannelWriter` | Earlier successful rows remain; failed rows are counted and reported. |
| Quarantine | One transaction per channel, including the ownership record | Recorded merge policy and channel flags commit or roll back together. Release restores merge eligibility without enabling the channel. |
| Provider-group disablement | One transaction per batch of up to 250 groups | Completed batches remain; changed or ineligible groups are reported as failures. |
| Run results | One transaction per `RunResultRepository::replace` call, inserting buffers of 250 rows | A replacement rolls back as a unit. When called inside native-settings application, it also depends on the outer transaction. |

Channel plans carry two before-states: identity/scope evidence and raw metadata
values. Writers reload and lock current models before comparing these snapshots.
Do not replace this with a timestamp check or save the partially selected models
used for preparation. `ChannelAttributes::readColumns` must retain every field
needed by planning and the concurrent-edit checks.

Cancellation stops subsequent work; it does not undo committed stages. Results
are saved at explicit checkpoints, including the group-setup failure path. An
exception before final result persistence can leave earlier native audit rows
and committed changes without a final channel report. Diagnose using the run's
logs, saved results and current state together.

Source-cache publication is a separate filesystem operation. Refresh uses a
shared lock and compares revisions before publishing; `writeLastGood` replaces
the file using a temporary file and rename. Database rollback does not revert
that file. Raw scraper responses are never persisted.

## Data Contracts At Service Boundaries

The PHPDoc aliases below describe existing arrays; they add no runtime wrapper
or dependency. Each shape is declared on its producer and imported by consumers
using `@phpstan-import-type`. Update the producer's definition when adding a
field, and check consumers and result exports. Optional keys use `?`; report
cells often use `''`, while absent match results use `null`.

| Contract | Definition | Meaning |
| --- | --- | --- |
| `CachedSourceEntry`, `SourceSnapshot` | [SourceCacheService](../src/Services/SourceCacheService.php) | Validated persisted source rows, fetch metadata and path; validation uses the current configuration's minimum count. |
| `SourceEntry`, `PreparedSourceEntry` | [ParsedSourceLoader](../src/Services/ParsedSourceLoader.php) | Source identity and aliases; preparation supplies `stream_id` and `guide_valid`, and can add guide-only rows and profile aliases. |
| `ChannelReportRow` | [ChannelAttributes](../src/Support/ChannelAttributes.php) | Display fields shared by matching, scope exclusions and availability. |
| `DecisionState`, `NativeMetadata` | [ChannelAttributes](../src/Support/ChannelAttributes.php) | Raw identity/scope evidence and editable metadata snapshots used to detect intervening changes. |
| `MatchResult`, `MatchRow` | [ChannelMatcher](../src/Services/ChannelMatcher.php) | A decision and its candidates; the report row also carries internal `_...` snapshots for planning. |
| `ExcludedRow` | [ScopeFilter](../src/Services/ScopeFilter.php) | An exclusion reason and display fields, without a match's internal write snapshots. |
| `MetadataView`, `WritePlanRow` | [WritePlanBuilder](../src/Services/WritePlanBuilder.php) | Display-level before/after values, native column changes and their expected raw originals. |
| `FailoverSummary` | [ChannelRepository](../src/Services/ChannelRepository.php) | Deduplicated valid backups; summary maps are keyed by the primary channel ID. |

`native_columns` describes the intended native metadata state;
`native_changes` contains only fields to write; `native_originals` contains the
raw before-values for those fields. `decision_state` separately protects the
identity and scope evidence that justified the change. Preserve that distinction
when adding a metadata field.

## Cache Ownership And Lifetime

`GuideLinkServices` belongs to an action instance. Normal plugin dispatch creates
a fresh instance for the next action. Keep it out of process-wide singleton
bindings: country rules deliberately assume the installed pack is fixed during
that action. The derived caches below retain only their most recent inputs and
result, rather than one entry for every playlist.

| Data | Lifetime and refresh rule |
| --- | --- |
| Parsed source files | Persistent across runs, keyed by country/provider/source/region. Every reader validates structure and the profile's minimum count. Freshness is checked separately when required. |
| Prepared source snapshot | One playlist sync; preparation passes its validated snapshot to the loader. The next playlist reads source data again. |
| Parsed country rules | Once per country per `CountryRulesRepository` instance, normally shared for an action's batch. A new action reads the installed pack again. |
| Guide map | Last result on `GuideMapService`. Access and current guide rows are checked before comparing the complete input; parent timestamps alone are insufficient. |
| Guide aliases and profile aliases | Last preparation on `ParsedSourceLoader` / `ProfileAliasResolver`. Current source data, selected sets and guide validation are obtained before reuse. |
| Matcher index | Last source entries, country rules and thresholds on `ChannelMatcher`. Any input difference rebuilds it. |
| Failover summaries | Local to an availability report and passed explicitly to both analyses. A later report loads current channels and backups. |
| Scrubber column list | Local to each `apply` call; the next call reads the schema again. |

In-memory reuse saves derivation work, not ownership checks or current database
reads. Do not key it only by profile ID, playlist ID or `updated_at`. The
`PreparationReuseTest`, `GuideMapTest` and `ProfileAliasesTest` suites cover edits
between calls, including changes that leave timestamps unchanged.

## Choosing Where To Make A Change

- **Profile options:** start with `plugin.json`, `ProfileDefaults` and
  `ProfileSettings` (or `SourceSettings` for source options). Update configuration
  help and the relevant form/contract tests. Keep saved keys stable when renaming
  user-facing labels.
- **Matching rules:** use `ChannelMatcher` and country-pack rules; exercise
  `MatchingSafetyTest`, including ambiguity, regional and timeshift cases.
- **Metadata writes:** update `ChannelAttributes`, `WritePlanBuilder` and
  `ChannelWriter` together. Use `ChannelWriteSafetyTest` and `SyncStabilityTest`
  to cover both projected reads and locked rereads.
- **Scope or disablement:** trace `ScopeFilter`, `MatchDecision`, quarantine and
  group planning together. Use `ScopeSafetyTest`, `QuarantineSafetyTest` and
  `GroupPlanningTest` to preserve the distinction between exclusion and protection.
- **Reuse or freshness:** keep ownership and lifetime explicit, and extend the
  relevant reuse/cache test with a second call after changing the input.

For documentation-only changes, check formatting, PHPDoc syntax and links; no
new behaviour tests are needed. For runtime changes, use the focused validation
gates below and keep the existing safety checks visible rather than introducing
a generic pipeline or cache framework.

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

Release 0.1.3 validation on 2026-10-22 used PHP 8.4 and isolated databases:

- Current upstream host `7ce0d553d5915636a89e4ba2ac8e5a429970fedf`:
  226 tests / 1,528 assertions passed on PostgreSQL 16. Three tests for optional
  host visibility/caching APIs were skipped; ordinary profile saving and the
  all-fields-visible fallback were exercised.
- Host PR #1655 at `b3e94635f1b918e77b1406585469ee0203287c43`:
  229 tests / 1,556 assertions passed on SQLite, including conditional fields and
  retained hidden settings. The revised form and scrubber query-count tests also
  passed on PostgreSQL.
- All 112 PHP files passed lint and Pint. Standalone validation and all nine
  release-tool tests passed. The packaged 0.1.2-to-0.1.3 update passed reviewed
  installation, trust and integrity checks while preserving a saved profile and
  playlist assignment. The isolated install test used the host's fake scanner.

Form interactions were checked through Livewire integration tests. No new manual
browser walkthrough, live scraping or production load benchmark was performed.

For 0.1.2 on 2026-10-21, the full plugin suite passed with 206 tests
and 1,324 assertions on each of SQLite and PostgreSQL 16 under PHP 8.4, using the m3u-editor reference commit
`aa550eeae5a0b1f0907907a63abe1c566025a978` and isolated PostgreSQL 16 and SQLite
test databases.
Regression coverage includes native import cleanup and auto-merge, repeated sync,
legacy group restoration, disabled backups, quarantine (including availability
repair), native logo fallback, profile-managed playlist defaults, native channel
import and generated M3U ordering/numbering, layout conflict reports and native
configuration rollback, single playlist-update dispatch, source group
deduplication, group preservation and avoiding unused failover queries during
sync. Availability regression tests cover uncertain coverage, custom alias
thresholds, partial repair cancellation, quarantine and failover changes between
planning and apply. Result persistence tests cover batch boundaries and rollback
after a later batch fails. Insert buffers are limited to 250 records; historical
scrubber channel IDs are deduplicated in SQL. Write safety tests cover exact
numeric-looking stream IDs, stale metadata plans, already-applied and partially
applied plans, playlist moves, cancellation before save, duplicate group create
plans and distinct confirmed/review source counts.

Further regression coverage includes:

- Disabling review channels and eligible provider groups, stale group identities,
  newly imported or unquarantined members, cancellation, replay and batched cleanup.
- Streamed result serialization across batch boundaries and rollback after later
  JSON or database failures. Shared decisions preserve matching/report consistency.
- Installation after playlist import leaves playlists untouched; default layout
  application preserves existing channel enabled states and explicit profile choices.
- Cancellation before actions, during source fetch/matching/coverage, and after
  write checkpoints; cancellation never triggers a retry or stale-cache success.
- Provider identity and target-group changes between planning and writing,
  quarantine generation/replay safety, and restoring recorded merge preferences
  on purge without enabling channels.
- Invalid scope regexes, comma-containing quantifiers, exact managed-category
  scope, and disabling all group management through the profile setting.
- Duplicate guide records and conflicting number labels, source deduplication
  with explicit priority, malformed caches, and fingerprint identity conflicts.
- Shared refresh deduplication, independent batch failures, retained partial
  results, cached reads without credentials, permanent HTTP failures without
  retries, and transient HTTP recovery.
- Unique failover counts by channel ID, including identical titles and shared
  references; invalid fallback ownership/type cannot establish coverage.
- Standalone native setup preserving intervening configuration changes, and
  repair failures retaining successful repairs and per-channel error details.
- Native alert delivery with fakes: persisted suppression, reminder intervals,
  source fallback/escalation/recovery, failed-write recovery, partial batches,
  preview/apply isolation, cancellation, recipient permissions, host success
  suppression, delivery failure isolation and schema reapplication/purge.
  Neither these tests nor the upgrade smoke check send real external alerts.
- Cache minimums applying to every reader, copied/future-dated/corrupt caches,
  overlapping refresh publication, failed-write temporary cleanup and avoiding
  immediate retries after HTTP 429.
- Setup-check cancellation and failed-fetch deduplication, automatic availability
  opt-outs, deletion of a selected scrubber between planning and apply, and
  consistent alert identities for single assigned-playlist and manual runs.
  Empty automatic selections skip quietly instead of raising failure alerts.
- PostgreSQL decimal sort values do not cause repeated group writes; fractional
  group orders remain visible to planning and stale-plan checks. Layout tests
  compare persisted JSON rules without assuming JSONB object-key ordering.

These are deterministic local regressions; concurrent workers on a production
database have not been load tested. A read-only live audit on 2026-10-17 checked
3,202 channels and 339 guide mappings: 681 confirmed matches, 16 review matches,
1,497 unmatched and 1,008 excluded. It proposed zero metadata, group or quarantine
changes. Audit code was staged temporarily and removed; no installed plugin,
playlist, channel or cache data was changed. A separate fresh-fetch check was
blocked by scrape.do's exhausted monthly quota (HTTP 401); the existing 297-row
source cache remained readable. Fresh parser behavior is fixture-tested, not
verified against a successful live fetch during this audit.

A subsequent production update exercised the reviewed install, Horizon restart,
PHP-FPM reload, native playlist import/merge and automatic hook, sync and repeat
previews. Managed group IDs/order and quarantine remained stable; the final
preview proposed zero edits. EPG logo selection and a sample image fetch passed.
Native alert suppression, recovery and rate-limit fallback were checked with
delivery/HTTP intercepted and test database changes rolled back. Existing
profiles and schedules were retained; fresh source fetching was not retried.

Standalone validation, PHP lint (109 files), Pint, and all nine
packaging/release-note tests passed. The packaged 0.1.0-to-0.1.1 upgrade
passed host install review, trust, schema creation, integrity verification,
registry diagnostics, and health checks while preserving profile settings and
playlist assignments. Install testing used the host's fake scanner in an isolated
local environment; it was not a malware scan.
Rebuild the image whenever the m3u-editor reference checkout changes
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
- Write `.github/release-notes/v<version>.md` with user-visible changes, upgrade
  behaviour and validation evidence before tagging.
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
  checksums, and creates a **draft GitHub release** with the three assets attached
  and the authored `.github/release-notes/<tag>.md` as its body. Missing or empty
  notes fail the release job.
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
review the authored notes, and select **Publish release** after completing the checks below. Users download the attached
`guidelink.zip`. There is no need to upload a locally built ZIP or manually create
another release. For the next release, use a new version and matching tag.

If validation fails, no draft is created. For an infrastructure failure, fix the
repository settings and rerun the failed jobs. For a source fix, commit it and use
a new version/tag; existing tags keep identifying the code originally tested.

### Release Notes

Write `.github/release-notes/v<version>.md` before creating the tag. Explain the
concrete changes users will notice, settings they need to review, changed defaults,
upgrade steps, and what was tested. Mention material validation limits. A list of
commit subjects is useful for reviewing the scope, but is not a release description.

The release workflow reads the notes from the tagged commit. Notes are maintained
with the release tooling and excluded from the plugin ZIP. To correct the current
version's existing draft, edit its notes file and push `main`; **Update Draft
Release Notes** updates and verifies the draft body using GitHub's workflow token.
It does not publish the release or replace its tag or assets. If the current
version has no draft, or has already been published, it makes no change. Review
published-release corrections manually in GitHub.

### Commit Summary Helper

`scripts/release-notes.py` generates a scope-review summary from Git history. Use clear
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

The helper does not supply the release body. The workflow passes the authored
Markdown to `gh release create --notes-file`; it does not use commit summaries or
GitHub's PR-only note generator as the final release notes.

## Prepare Release Assets

The GitHub workflow builds these automatically. Use the following only when you
need a local archive for testing; there is no committed release directory.

Python 3 is required only for packaging, not for running the plugin.

```bash
python3 -m unittest discover -s tests/release -v
python3 scripts/package-release.py
cd dist/v0.1.1
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
