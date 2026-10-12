# Configuring GuideLink

Profiles define the desired source, matching rules, scope, and native settings.
Preview Sync shows the proposed changes; Run Sync puts the profile into effect.

## First-Run Flow

1. Create or edit a profile in **Data → Profiles**. A Guide EPG is required,
   including for native playlist sources. The selected EPG must contain usable
   channel-number-to-stream-ID mappings.
2. In **Data → Playlists**, choose the profile for the target playlist and enable
   the assignment. The profile must also be enabled.
3. Optionally use **Preview Sync** and inspect **Results** or **Run History**.
   The report includes native auto-merge/scrubber settings and channel metadata
   changes. Preview saves cache/results but does not change native settings,
   channels, profiles, or assignments.
4. Use **Run Sync** and confirm. GuideLink validates the source and profile,
   applies changed native settings, then syncs channel metadata and groups.
   A previous preview or setup report is not required; each run uses the current
   profile and current playlist state.

The profile is the source of truth for settings GuideLink manages: changes take
effect on the next Run Sync, including scheduled runs in apply mode. Native
settings edited directly in m3u-editor will be restored to the profile's values
for those managed fields. Unrelated native options are preserved. An explicitly
selected user-managed scrubber is inspected without changing it.

Sync can target one playlist or all enabled assignments selected for sync. Native
settings are written to each target playlist and its managed scrubber; the native
merge process can subsequently involve the configured backup playlists.

Run Sync does not start a scan or failover rebuild. m3u-editor's native playlist
sync and recurring scrubber lifecycle handle that processing. Scans can also be
started separately in m3u-editor.

The visible actions are **Check Channels**, **Preview Sync**, **Run Sync**, and
**Preview Alias Set**.
The original checklist and individual native maintenance actions remain available
by action ID for troubleshooting.

## Data Tab

Profiles, Playlists, Alias Sets, and Aliases use plugin-owned tables declared in
`plugin.json` and rendered by m3u-editor's generic plugin table UI:

- `plugin_guidelink_profiles`
- `plugin_guidelink_links`
- `plugin_guidelink_alias_sets`
- `plugin_guidelink_aliases`

Use the plugin **Data** tab to manage these tables.

Playlists are prefilled from the user's playlists. Rows start
disabled until a profile is selected and the playlist is explicitly enabled.
Playlist rows are not manually created from this table. Clearing an assignment
resets its GuideLink profile/configuration without removing the playlist row.

### Shared channel aliases

In **Data → Alias Sets**, create a named set, choose its country and reference
Guide EPG, and enable it. **Bundled Defaults** selects its source:

- Choose **Sky UK channel aliases** for read-only defaults from the installed JSON.
- Leave it empty for a custom set managed through **Data → Aliases**.

In **Data → Profiles**, select the desired **Alias Sets**. Several profiles can
share a set, and a profile can select both bundled and custom sets. Run **Preview
Sync** before applying changes.

For a custom set, add full alternative channel names in **Data → Aliases**, choosing
one or more compatible custom sets and a target guide channel. For example, map
`DISC CHNL` to Discovery Channel. Shared edits and disabling an alias affect every
custom set selected on that entry. The target picker only offers matching identities
across those sets’ reference guides. Bundled sets are excluded from this picker.

### Read-only bundled defaults

The Sky UK dataset contains 44 alternative names against 30 guide entries,
including explicit +1 services. Alias Sets stores the dataset reference; profiles
store their selected set IDs. The entries themselves come directly from
`src/AliasSets/sky-uk.json`. They do not appear in the editable **Aliases** table.
Replacing the plugin ZIP replaces this data immediately for subsequent reads.
There is no import, reset, revision comparison, database refresh or scheduled
alias update. The revision displayed in a preview is informational.

Custom aliases remain in the database and survive plugin updates. To add names
alongside bundled defaults, create a custom set and attach both sets to the profile.
Custom aliases cannot redirect another channel’s existing name. Choosing a bundled
dataset on an existing custom set makes its database aliases inactive for that set;
leave **Bundled Defaults** empty again to use those custom entries.

Use **Actions → Preview Alias Set**, choose a saved set, and open its run to inspect
the guide channel, guide ID and alternative names. The result includes counts and
a complete entry list; the run log provides one readable line per guide channel.
This works for bundled and custom sets, including disabled sets/entries, without
changing their contents. The current host does not expose a custom submenu on an
Alias Set row, so this preview uses its supported action menu.

### Matching rules for sets

At runtime, each target guide ID must resolve uniquely in the profile’s source and
guide. Bundled targets must match the expected channel name or a verified spelling
from the JSON, allowing normal formatting differences but no fuzzy approval of
identity. Custom sets reused with a different guide must share a normalized target
name across both guides. Number-only guide labels cannot establish identity.
The set’s country must match the profile’s country.

Explicit aliases may contain different digits from their target, for example
Movies4Men → GREAT! Action. Timeshift checks still apply: +1 names must point to +1
services. Automatic and fuzzy matching retain numbered-service checks. Missing
targets produce a warning and their aliases are skipped; ordinary matching still
applies. Ambiguity, conflicting names and invalid configuration stop the run before
native settings or channel writes. A selected disabled/deleted set must be restored
or removed from the profile before running again.

Matches record `profile_alias` and the set ID/name in Run Results. Bundled defaults
and custom edits are read on each preview, report, manual sync or scheduled sync.

### Regional matching

For names that omit a region, Sky's unique main-lineup feed takes precedence over
entries explicitly classified as regional alternatives. For example, Sky region 7
selects BBC One North West. If the source does not identify a unique main feed,
multiple regional candidates require review. Explicit regional names retain their
own targets; channel-number ordering never chooses the winner.

## Scheduling

Plugin-wide settings only control schedules. Playlist behavior lives in
Profiles.

| Setting | Default | Use |
| --- | --- | --- |
| Enable Scheduled Availability Report | `false` | Runs the availability report on the Schedule Cron. |
| Schedule Cron | `0 * * * *` | Availability report cron expression. |
| Enable Scheduled Sync | `false` | Runs sync preview/apply on the Sync Cron. |
| Sync Cron | `0 3 * * *` | Sync cron expression. |
| Scheduled Sync Mode | `Preview Only` | Use `Preview Only` for dry-run reports or `Apply Writes` for scheduled native settings and metadata writes. |
| Enable Scheduled Source Refresh | `false` | Runs `Refresh Source Cache` on the Source Refresh Cron. |
| Source Refresh Cron | `30 2 * * *` | Source refresh cron expression. This is separate from Source Cache Days. |

Notes:

- m3u-editor evaluates scheduled plugins every minute with
  `plugins:run-scheduled`.
- The `playlist.synced` hook is separate from cron. It runs the availability
  report after playlist sync when the playlist has an enabled GuideLink
  assignment.
- Scheduled source refresh remains separate from scheduled sync. **Preview Sync**
  and **Run Sync** (including scheduled sync) prepare a missing scraper cache.
  Existing last-good caches are reused; refreshing them remains a separate job.

## Source And Scraper Settings

| Setting | Default | Use |
| --- | --- | --- |
| Source Mode | `Scraper Cache` | Use cached provider listings or a native playlist as the canonical source. Both modes require a numbered Guide EPG. |
| Source Playlist | empty | Required for native playlist mode. Uses enabled live channels with usable channel numbers from the selected playlist. |
| Country | `UK / Ireland` | Registry-backed country rules for aliases, exclusions, and normalization. |
| Provider | `Sky` | Registry-backed source provider family for the selected country. |
| Provider Source | `ViewersVault` | Registry-backed source for the selected provider and country. |
| Region | `1` | Sky region number or exact region name. Region `7` is Granada. |
| Scraper Backend | `Direct HTTP` | Registry-backed scraper backend. API-backed scrapers are available for pages that block direct HTTP. |
| Scraper API Key | empty | Enter the key in this profile for `scrape.do` or `OloStep`. Direct HTTP does not require one. |
| Scraping Timeout Seconds | `60` | Per-page HTTP timeout. A refresh fetches the region index, a linked regional table when needed, and the channel page. |
| Source Cache Days | `2` | Cache freshness threshold during source refresh. Set `0` to fetch on each refresh invocation. Sync reuses an existing last-good cache regardless of age. |
| Scraper Max Retries | `3` | Attempts per page before parsed last-good cache fallback. |
| Minimum Source Channels | `100` | Rejects suspiciously small parsed source output. |
| Maximum Drop Percent | `20` | Refuses to replace last-good cache if the parsed channel count drops too far from the previous cache. |

Country, provider, source, and scraper options are dynamic. The UI filters
provider and source choices from the registries and country provider packs, and
runtime validation rejects unsupported combinations if stale saved data bypasses
the UI.

Country options come from `src/Sources/Countries/<Country>/country.json`.
`key` is the saved profile/action value, such as `uk` or `us`; `name` is the UI
label, such as `UK / Ireland` or `United States`. The country folder name
(`UK`, `USA`, `CA`) is only the filesystem pack location.

Set scraper API keys through **Data → Profiles → Scraper API Key**. GuideLink uses
the saved profile key and does not read keys from container environment variables.

## Source Cache Behavior

Parsed source caches are keyed by country, provider, source, and region:

```text
plugin-data/guidelink/source-cache/parsed/
```

Raw scraper responses are never persisted. A failed refresh falls back to the
parsed last-good cache when available, and new parsed output must pass
validation before replacing that cache.

With defaults, worst-case failed scraping is roughly:

```text
up to 3 pages x 3 retries x 60 seconds
```

After upgrades or source metadata changes, the next refresh may repopulate
derived cache files.
Cache names containing non-ASCII characters, punctuation, or underscores include
an identity hash so different regions and providers cannot share a cache by
accident. Those names require one fresh fetch after upgrading from older cache
paths; simple names such as `sky_uk_viewersvault_region_1` retain their paths.

ViewersVault uses its current per-region channel tables. Regions such as Granada
are linked from the index and fetched through the selected scraper. The previous
ViewersVault summary-table format is no longer supported. TV Channel Lists uses
its own current region summary table.

## Matching And Scope Settings

| Setting | Default | Use |
| --- | --- | --- |
| Guide EPG | required | Numbered guide EPG used to validate channel numbers and supply consistent stream IDs for auto-merge. Setup and channel review/sync reject missing, unavailable, or unmappable guides. |
| Include Channel Scrubber Logs | `true` | Include scrubber-related context in availability/repair reporting when m3u-editor exposes it. |
| Include Group Prefixes | empty | No group-prefix restriction by default. Enter prefixes to narrow scope; exclusions and preserved groups still apply. |
| Exclude Group Regex | empty | Regex patterns for groups to exclude even if their prefix matches. |
| Preserve Groups | empty | Exact group names never moved or rewritten. |
| Preserve Group Name Regex | empty | Regex patterns for groups never moved or rewritten. |
| Preserve Channel Title Regex | empty | Regex patterns for channels never rewritten. |
| Include Managed Source Groups | `true` | Allows source categories/managed groups to be included in scope and created during apply. |
| Disable Other Groups | `false` | Explicit post-match quarantine. During apply, channels still left in unmanaged provider groups are disabled after managed matches are moved/written. Skipped if channel writes fail or the run is cancelled. |
| Repair Mode | `Report Only` | Default repair mode. Apply currently only supports `Report Only` and `Re-enable Master With Enabled Failover`. |

Availability reports always include disabled live channels and attached failover
coverage. Repair reports also always inspect failovers. Failover
totals include hidden backups, but enabled-coverage counts still use the channel
enabled flag; they are not a measurement of current stream health.

New defaults do not overwrite explicitly saved profile choices. Change a profile
in **Data → Profiles**, then use Preview Sync / Run Sync to apply its settings.

## Native Setup Settings

These profile fields define the desired native m3u-editor settings. Preview Sync
shows their differences; Run Sync applies them along with channel metadata.
Manual write actions remain confirmation-required.

Native recovery depends on current host schema support for
`channels.last_scrubber_live`,
`channel_scrubbers.protect_failover_channels`, and
`channel_scrubbers.rebuild_failovers_after_scan`. The advanced setup checklist audits
these native tables and columns when troubleshooting host compatibility.

| Setting | Default | Use |
| --- | --- | --- |
| Auto-Merge Preferred Playlist | assigned playlist | Preferred source of primary streams when merging playlists. Catch-up preference and resolution can take priority. |
| Backup Playlists | empty | Select additional backup playlists by name, in priority order. Leave empty to use only the assigned playlist. |
| Deactivate Failover Channels | `true` | Hidden-failover policy. Auto-merge disables backup channels after master selection, and GuideLink uses the same policy to keep the scrubber from re-exposing them. |
| Merge Only New Channels | `false` | Allows complete native rebuilds instead of only handling new channels. |
| Force Complete Re-Merge | `true` | Rebuild native failover topology fully when requested. |
| Auto-Merge Key | `stream_id` | Native merge key for live-channel failovers. |
| Prioritize By Resolution | `true` | Prefer higher-resolution streams using native stream statistics, probing when needed. |
| Prefer Catch-up As Primary | `true` | Prefer eligible catch-up streams before applying resolution and playlist preferences. |
| Scrubber-aware Primary Selection | `true` | Exclude channels confirmed dead by the scrubber from primary selection. Existing profiles use this recommendation unless explicitly configured otherwise; Run Sync applies the profile value. |
| Existing User-managed Scrubber | empty | Leave blank for GuideLink-managed configuration; an explicitly selected user-managed scrubber is audited without being overwritten. |
| Exclude Disabled Groups From Master Selection | `true` | Avoid selecting channels from disabled groups as masters. |
| Run Scrubber After Playlist Sync | `true` | Let native scrubber run after playlist sync. |
| Scan Disabled Channels | `true` | Keeps recovery health state fresh for hidden failover-topology channels. |
| Disable Dead Channels | `true` | Let native scrubber disable channels whose streams fail probing. |
| Rebuild Failovers After Scan | `true` | After a successful scrubber scan, rerun native auto-merge with fresh health state so promotions and failover recovery stay topology-aware. |
| Scrubber Probe Method | `HTTP` | Fast native check method; switch to FFprobe when provider behavior requires it. |
| Scrubber Probe Timeout Seconds | `10` | Per-stream native scrubber timeout. |

GuideLink-managed scrubbers always keep direct live-channel re-enable and VOD
scanning off. Native auto-merge handles primary/failover recovery after scans.
These settings are no longer profile or action options, and old saved values
cannot override this policy when setup is applied. User-managed scrubbers remain
audit-only.

Batch processing is configured in m3u-editor's native scrubber UI. GuideLink leaves
an existing scrubber's batching choice unchanged and uses the native default
when creating one.

Auto-merge decides which channel is the visible master and hides the
rest as failovers when `Deactivate Failover Channels` is enabled. The scrubber
uses the same policy: it can record hidden failovers as live, but it will not
re-expose them directly. The next failover rebuild uses the fresh health state to
make topology-aware recovery decisions.

## Advanced Matching

Matching uses current source names and aliases, plus names from the selected
Guide EPG linked by validated channel number. Guide aliases that identify more
than one canonical channel are ignored. Generic rules handle spacing, punctuation,
configured number words, quality/frame-rate tags, optional channel labels, and unambiguous word
abbreviations. Initials are derived from consecutive source-name words and require
unchanged identifying context, in either the playlist or guide name. Repeated broadcaster prefixes are learned from the
source catalogue. Composite labels separated by a slash or spaced dash resolve
only when their recognised alternatives identify one service. Conflicting exact
names or aliases require review; region-free aliases retain the configured
lineup’s regional default. An explicit region can resolve to a national base
channel only when the region is recognised and the catalogue has no regional
versions of that service.

Recognised regional suffixes also work with guide aliases and confirmed older
names. The UK pack expands EU to Europe without discarding a region from names
such as HUM Europe. Punctuation in a provider prefix is ignored. Fuzzy matching
requires exact agreement between alphabetic identifiers of three characters or
fewer, so similar abbreviations cannot silently choose another broadcaster.
Other differing words need a single-character spelling correction; longer
word extensions need two unchanged words of context to qualify as abbreviations.
Unicode letters, combining marks, and decimal digits are retained while decorative
quality markers are removed; similarity counts characters rather than UTF-8 bytes.

A country's word rules come from its pack. The UK pack defines English numbers,
conjunctions, channel labels, plural suffixes, and the trailing `Live` label;
other packs supply their own conventions. A configured presentation label can
be omitted after an otherwise complete multiword name; a one-word brand is
insufficient. A primary-service 1 may differ
only when both remaining names and the provider’s channel ID agree.

In scraper mode, numbered channels present only in the selected guide are
available as a fallback for exact or structured name matches. Duplicate guide
slots for existing services are skipped, fuzzy fallback is disabled, and these
channels retain their current groups. Native playlist mode remains limited to
its chosen source playlist.

Channel-specific aliases belong to selected sets and target guide IDs. Bundled
entries live in the installed JSON; custom entries live in the database.
The bundled Sky UK dataset includes established old names as alternative names.
General formatting and regional rules remain in the country JSON files.
Decorative playlist headings such as `#### SPORTS ####` are excluded generically.
Fuzzy matching preserves distinguishing numbers and plus
suffixes. Channels missing from both the source and selected numbered guide
cannot be made valid matches by lowering thresholds; unresolved rows stay unchanged.
Accepted sync matches set both the custom stream ID and the native EPG-channel
association to the selected Guide EPG record. Preview shows both changes without
writing them; unresolved matches retain their existing EPG associations.

Saved results explain unresolved matches, including conflicting names, numbered
services, timeshifts, and missing guide entries. The defaults below are conservative
and should normally be left alone.

The UK identity aliases were reviewed against [Sky's Sky One announcement](https://skygroup.sky/en-gb/article/iconic-tv-channel-sky-one-is-coming-back-as-the-new-home-of-sky-s-unmissable-entertainment),
[Premier Sports' migration information](https://www.premiersports.com/us/content/60/faqs-account-migration),
[AMC's rebrand announcement](https://www.amcglobalmedia.com/2023/10/31/amc-networks-international-uk-unveils-a-fresh-and-cohesive-new-look-for-its-uk-portfolio-of-channels-and-streaming-services/),
[CBS Drama's broadcast rebrand promo](https://theident.gallery/player.php/REALITYXTRA-VIDEO-2022-PROMO-LAUNCH-1-280622),
[Gemporia's channel information](https://www.gemporia.com/en-gb/ways-to-watch/?watch=app),
and the documented [GREAT! rebrands](https://www.avforums.com/news/great-launches-entertainment-network-on-25th-may.18724/)
and [True Movies / Romance history](https://en.wikipedia.org/wiki/Great%21_Romance).
Cinema Select/Hits use the stable Pop-Up aliases in the current catalogue,
corroborated by the [Sky guide-change discussion](https://helpforum.sky.com/t5/What-s-On/Changes-to-the-TV-Guide-May-2026/m-p/5262524).
There is no permanent Superheroes-to-Greats rewrite: seasonal labels have occupied
different cinema services. A provider retaining such a label needs a matching
source/guide alias or independent feed evidence. Bloomberg EU uses the generic
regional-label rule and the guide's Bloomberg alias without a JSON rename.
NOW 90s → NOW 90s & 00s was explicitly confirmed by the playlist owner.
The Utsav names follow [JioStar's UK/Europe rebrand announcement](https://www.jiostar.com/news/jiostar-brings-back-the-iconic-star-brand-across-the-uk-and-europe/).
ARY Family follows the [UK broadcaster's retained programme information](https://arydigitaltv.uk/good-morning-pakistan/) and
[reported rebrand](https://www.bizasialive.com/ary-family-rebrands-to-ary-digital-in-uk/).
PBC Punjabi identifies [Panjab Broadcasting Channel](https://panjabbc.com/wp-content/uploads/2023/03/PBC-Media-Pack.pdf),
not the similarly named PTC Punjabi; this semantic alias keeps that distinction
out of fuzzy spelling rules.
These are name-identity checks, not verification of the IPTV stream's content.

| Setting | Default | Use |
| --- | --- | --- |
| Fuzzy Threshold | `90` | Minimum fuzzy score before a match can be considered. |
| Minimum Auto Score | `90` | Minimum score for automatic write eligibility. |
| Minimum Auto Margin | `12` | Required gap between best and next-best fuzzy candidate. |
| Sort Floor Offset | `10` | Allowed token-sort score gap below the fuzzy threshold. |
| Minimum Alias Words | `2` | Minimum words for alias matching. |
| Minimum Alias Characters | `4` | Minimum characters for alias matching. |
| Minimum Set Tokens | `2` | Minimum token count for set-style matching. |
| Minimum Ratio Length Ratio | `0.7` | Length-ratio guard for full-ratio matching. |
| Minimum Ratio Score | `90` | Full-ratio score floor. |
| Minimum Partial Length Ratio | `0.65` | Length-ratio guard for partial matching. |
| Minimum Partial Length | `5` | Minimum text length for partial matching. |
| Minimum Partial Ratio Score | `80` | Partial-ratio score floor. |
| Maximum Short Token Length | `2` | Tokens at or below this length are treated as short tokens. |
| Minimum Token Similarity | `60` | Token similarity floor. |

## Actions

Every action records its run status and logs. The tables below distinguish those
records from changes to playlist data, native settings, and saved report rows.

| Action | Writes data | Use |
| --- | --- | --- |
| Check Channels | Run results only | Reports disabled primaries, failover coverage, scrubber context, and source coverage using the playlist's assigned profile. |
| Preview Sync | Cache and run results only | Previews native settings, matching, groups, and channel writes using the current assigned profile. |
| Run Sync | Native settings, metadata/groups, cache and run results | Applies changed auto-merge/managed scrubber settings, then metadata/group writes using the current assigned profile. Requires confirmation; does not start a scan or rebuild. |
| Preview Alias Set | Run history only | Lists guide targets and alternative names for a saved bundled or custom set, including disabled entries. |

Advanced actions are hidden from the primary menu but remain available by action
id for maintenance, schedules, or troubleshooting:

| Advanced action | Writes data | Use |
| --- | --- | --- |
| Plugin Diagnostics | Run history only | Basic plugin load/configuration check. |
| Check GuideLink Setup | Cache and run results | Checks configuration and prepares missing or stale caches for enabled scraper-cache profiles. Requires confirmation. |
| Refresh Source Cache | Cache and run results | Manually fetches/parses source pages for enabled scraper-cache profiles and updates last-good source cache if validation passes. Requires confirmation. |
| Repair Channels | Run results only | Builds repair candidates using the playlist's assigned profile. |
| Apply Channel Repair | Yes | Applies supported repair mode from the playlist's assigned profile. Currently limited to re-enabling masters with enabled failovers. Requires confirmation. |
| Native Failover Setup | Run results only | Audits native m3u-editor auto-merge settings and mergeable channel coverage. |
| Apply Native Failover Setup | Yes | Updates native playlist auto-merge settings using the assigned profile plus any action overrides. Requires confirmation. |
| Rebuild Native Failovers | Yes | Invokes m3u-editor's native `MergeChannels` job for the playlist's saved auto-merge setup and records before/after totals. Requires confirmation. |
| Scrubber Setup | Run results only | Audits native m3u-editor scrubber setup, health state, and recovery readiness. |
| Apply Scrubber Setup | Yes | Creates or updates a GuideLink-managed native scrubber, or validates a selected user-managed scrubber. Requires confirmation. |

## Native Scrubber And Recovery

GuideLink uses m3u-editor's native scrubber and auto-merge state for stream-health
promotion and recovery.

The GuideLink-managed scrubber defaults to:

- scanning disabled channels,
- disabling dead channels,
- keeping direct live-channel re-enable off,
- protecting hidden failovers,
- rebuilding native failovers after successful scans.

Recovery remains topology-aware: native auto-merge can restore a previously
hidden failover/master when scrubber state says it is live again, without
broadly re-enabling manually disabled channels.

Each participating playlist needs its own recurring scrubber, including backup
playlists. Native scrubbers scan their own playlist; listing another playlist as
a failover source does not scan its streams. Assign and sync a GuideLink profile
for each participating playlist, then run their initial scans. Configuration
readiness alone does not prove stream health or recovery.

GuideLink does not currently need a `plugin_guidelink_channel_states`
promotion table. Scrubber audit rows record this native-first policy and the
current health context.

## Declared Capabilities

- `channel_processor`
- `scheduled`

## Declared Hooks

- `playlist.synced`
