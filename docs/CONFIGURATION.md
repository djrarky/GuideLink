# Configuring GuideLink

Profiles define the desired source, matching rules, scope, and native settings.
Preview Sync shows the proposed changes; Run Sync puts the profile into effect.

## First-Run Flow

1. Create or edit a profile in **Data → Profiles**. A Guide EPG is required,
   including for native playlist sources. The selected EPG must contain usable
   channel-number-to-stream-ID mappings.
2. In **Data → Playlists**, choose the **GuideLink profile** for the target playlist and enable
   **Use GuideLink for this playlist**. The profile must also be enabled.
3. Optionally use **Preview Sync** and inspect **Results** or **Run History**.
   The report includes native playlist layout, auto-merge/scrubber settings and channel metadata
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
merge process can involve the configured backup playlists.

After successful metadata writes and quarantine, Run Sync restores confirmed
matches to primary/backup selection, including previously disabled channels.
It uses m3u-editor's ranking and failover records with stored probe results.
It does not start a scan. Native playlist sync and recurring scrubbers continue
to perform stream checks; scans can also be started separately in m3u-editor.

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

### Profile form

The profile form has four open sections:

- **Profile details:** name and whether the profile can be used.
- **Lineup & programme guide:** lineup source, country and numbered programme guide.
  Website fields are labelled separately from the reference-playlist field.
- **Channel handling:** groups, logos, managed layout/imports, the unmatched-channel
  policy, new imports and exceptions. The import switch stays beside the disabling
  policy because imports can enable channels before GuideLink next applies a sync.
- **Backups & stream health:** preferred/backup playlists, hiding backup entries,
  checker selection and the main stream-check choices.

Downloads, pattern exceptions, matching scope, ordering/numbering, matching rules,
primary-stream selection, backup rebuilds and stream-check details start collapsed.
Open a section to change its options; closing it does not reset or disable them.
Matching rules use comparison scores and safeguards, not percentage weights.

Every option has help underneath it. Existing setting keys, defaults and saved
values are retained. The download-service API key is masked, with a reveal button;
this changes its presentation, not its storage. Both source modes still require
a numbered programme guide.

With the accompanying m3u-editor form update, website settings appear for
**Listings website**, and **Reference playlist** appears for **Another playlist**.
The download-service key appears only for a method that requires one. Checker
controls appear when the selection is blank or names a GuideLink-managed checker;
user-managed checkers are configured in **Channel Scrubbers**. Report evidence
remains available in either case. Hidden settings keep their saved and unsaved
values, so switching back restores your choices.

The loading optimisations and conditional fields require the host update as well
as this GuideLink update. Older hosts display all fields with the same helpers and
retain the same sync behavior. No existing profile settings need migrating.

**Save** changes the profile only. **Preview Sync** uses the saved profile and
records proposed changes (it can also prepare source cache). **Run Sync**, or a
scheduled sync in **Apply changes** mode, updates the playlists. A provider import
runs the availability-report hook; it does not apply GuideLink's cleanup policy.

On the plugin Settings tab, task switches stay visible. Cron expressions are in
**Schedule times**, and the alert reminder interval is in **Reminder options**.
These schedules use the host scheduler's timezone.

### Shared channel aliases

In **Data → Alias Sets**, create a named set, choose its country and reference
programme guide, and enable it. **Included alias dataset** selects its source:

- Choose **Sky UK channel aliases** for read-only defaults from the installed JSON.
- Leave it empty for a custom set managed through **Data → Aliases**.

In **Data → Profiles → Channel handling**, select the desired **Channel-name alias sets**. Several profiles can
share a set, and a profile can select both bundled and custom sets. Run **Preview
Sync** before applying changes.

For a custom set, add full alternative channel names in **Data → Aliases**, choosing
one or more compatible custom sets and a target guide channel. For example, map
`DISC CHNL` to Discovery Channel. Shared edits and disabling an alias affect every
custom set selected on that entry. The target picker only offers matching identities
across those sets’ reference guides. Bundled sets are excluded from this picker.

### Read-only bundled defaults

The Sky UK dataset contains 58 alternative names against 33 guide entries,
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
leave **Included alias dataset** empty again to use those custom entries.

Use **Actions → Preview Alias Set**, choose a saved set, and open its run to inspect
the guide channel, guide ID and alternative names. The result includes counts and
a complete entry list; the run log provides one readable line per guide channel.
This works for bundled and custom sets, including disabled sets/entries, without
changing their contents. The current host does not expose a custom submenu on an
Alias Set row, so this preview uses its supported action menu.

### Matching rules for sets

At runtime, each target guide ID must resolve uniquely in the profile’s source and
guide. A bundled alias is used only when its target matches the expected channel
name or a verified spelling from the JSON. A changed name withholds that alias
and logs a warning; it does not stop current source matching or native EPG setup.
Unresolved channels affected by that alias retain their metadata, enabled state,
and failover relationships for review. Custom sets reused with a different guide must share a normalized target
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

Plugin-wide settings control schedules and native alerts. Playlist behavior lives in
Profiles.

| Setting | Default | Use |
| --- | --- | --- |
| Check channels on a schedule | `false` | Run availability reports for eligible playlist assignments at the channel-check schedule. |
| Channel-check schedule | `0 * * * *` | Cron expression for availability reports. The default runs once per hour. |
| Sync playlists on a schedule | `false` | Run GuideLink sync for eligible playlist assignments at the sync schedule. |
| Playlist-sync schedule | `0 3 * * *` | Cron expression for GuideLink sync. The default runs daily at 03:00 in the scheduler's timezone. |
| Scheduled sync behavior | Preview changes | Choose Preview changes to record proposed edits, or Apply changes to update playlists. |
| Refresh website lineups on a schedule | `false` | Refresh website lineups used by enabled profiles at the source-refresh schedule. Each profile may reuse a recent saved lineup instead of downloading again. |
| Lineup-refresh schedule | `30 2 * * *` | Cron expression for source refreshes. The default runs daily at 02:30 in the scheduler's timezone. |

Notes:

- m3u-editor evaluates scheduled plugins every minute with
  `plugins:run-scheduled`.
- The `playlist.synced` hook is separate from cron. It runs the availability
  report after playlist sync when the playlist has an enabled GuideLink
  assignment with **Include in automatic and batch checks** enabled. Turning that flag off suppresses
  both automatic paths; an explicitly selected manual report can still run.
- Assigned-playlist actions with no eligible assignments, and source refreshes
  with no enabled scraper profiles, skip quietly without failure/recovery alerts.
- Scheduled source refresh remains separate from scheduled sync. **Preview Sync**
  and **Run Sync** (including scheduled sync) prepare a missing scraper cache.
  Existing last-good caches are reused; refreshing them remains a separate job.

## Native Alerts

GuideLink uses m3u-editor's notification centre and its existing **Discord, Slack
and Telegram** alert channels. Configure destinations in m3u-editor's alert
settings; GuideLink does not store separate webhook credentials or provide an
email transport. No external message is queued when the host has no alert channel
enabled. In-app notifications still work independently.

| Setting | Default | Use |
| --- | --- | --- |
| Notify about problems and recovery | `true` | Report failures, saved-lineup fallback and recovery through m3u-editor notifications and its configured alert channels. Preview and scheduled report failures are included. |
| Unresolved-problem reminder interval (hours) | `24` | Send another reminder when the same problem is seen after this interval (1–168 hours). New problems, worsening incidents and recovery are reported immediately. Recovery also follows the host notification preferences. |

Alerts cover failed actions and playlist hooks, including invalid configuration
and failed writes. A source refresh that cannot fetch, parse, validate or save
listings raises a warning when a last-good cache remains usable, or a danger
notification when none is usable. Quota/request-limit and credential errors get
specific guidance. Refresh Source Cache marks the run as failed when it uses a
last-good cache after a refresh error; the cache remains available for sync.
Other actions that can finish using a last-good cache may still succeed while
raising the source warning. Ordinary unmatched/review channels and informational run
warnings do not generate individual alerts.

Administrators receive all incidents. Playlist incidents also notify the owner
when the playlist is accessible to that invocation; manual runs additionally
notify their initiator. Scheduled source-wide incidents notify administrators.
Recipients are deduplicated, and each incident is sent to the host's configured
external channels once, independently of the number of in-app recipients.

Incident state lives in `plugin_guidelink_alerts`, so suppression survives worker
restarts and reviewed upgrades. First failures, escalation from cached fallback
to no usable cache, and recovery notify immediately. Repeated failures are
suppressed until the reminder interval; reminders require another observation,
not a separate timer. Shared source configurations share an incident, including
across profiles. Credentials, fetch timeouts/retry policies and validation rules distinguish source
configurations; changing these starts separate incident history. Only a fresh,
validated and saved fetch resolves the matching source incident. Reading a cache
never claims recovery.

Operation incidents are scoped to playlist and operation. Batch failures are
reported per failed playlist. Preview/report failures can alert too, but their
success never clears an apply failure. Cancelled and skipped runs do not create
or resolve operation incidents. Recovery notifications honour the host's
suppression of success notifications; external channels retain their native
behaviour.

Messages identify the source or playlist and run number, without forwarding raw
exceptions, SQL, URLs or API keys. Inspect the run history for diagnostics;
administrators can inspect scheduled runs. Delivery uses the host queues and is
best effort: delivery errors do not alter the sync result, and claiming an alert
does not confirm receipt by the destination. A host/worker outage that prevents
plugin code from running still requires the host's own job monitoring.

## Source And Scraper Settings

| Setting | Default | Use |
| --- | --- | --- |
| Lineup source | Listings website | Use a supported listings website or an existing playlist for channel names, numbers and groups. Both options need a numbered programme guide. |
| Reference playlist | empty | When the lineup source is Another playlist, choose the playlist to copy the lineup from. Only enabled live channels with usable channel numbers are included. |
| Country | `UK / Ireland` | Choose the country whose channel naming rules and lineup sources GuideLink should use. |
| Lineup provider | `Sky` | Choose the television lineup to follow, such as Sky. This is the lineup provider, not your IPTV account provider. |
| Listings website | `ViewersVault` | Choose the website that supplies the selected provider's channel lineup. |
| Lineup region | `1` | Enter the provider's region number or exact region name to select the local channel lineup. For Sky, region 7 is Granada. |
| Download method | `Direct HTTP` | Download directly, or use a supported download service when the listings website blocks direct requests. |
| Download service API key | empty | Enter the API key for the selected download service. Direct HTTP does not need a key. |
| Download timeout (seconds) | `60` | Maximum time to wait for each page request. A lineup refresh can download several pages. |
| Reuse downloaded lineup for (days) | `2` | During a source refresh, reuse a saved lineup younger than this age. Set to 0 to download on every refresh. This does not schedule refreshes. |
| Download attempts | `3` | Maximum attempts per page, including the first request. Some errors stop attempts early; GuideLink can fall back to a valid saved lineup. |
| Minimum lineup size | `100` | Reject downloaded or saved website lineups with fewer channels than this. Choose a minimum below the normal size of your lineup. |
| Maximum lineup reduction (%) | `20` | Keep the previous saved lineup if a refresh loses more than this percentage of its channels. |

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
validation before replacing that cache. Empty, malformed, or duplicate-number
cache rows are rejected rather than silently removed. Conflicting services at the
same source number are not combined into aliases; equivalent duplicate names
can be combined, and explicit source/region priority still selects the intended
feed. Native playlist sources use the same rule for duplicate numbers.

A refresh batch fetches identical source configurations once, including forced
refreshes. Different credentials or validation settings remain separate. Failure
in one profile does not stop independent profiles; completed and failed results
are retained. Cancelled batches save completed profile results. Fresh caches do
not require an API key just to read them. Permanent HTTP errors such as 401/403
stop retries; HTTP 429 rate limits also stop immediate retries. Temporary failures
retain the configured retry policy. A refresh run using a last-good cache after
an error is marked failed, including each affected profile in a batch. Results
retain the `stale_fallback` decision, original fetch date and refresh error, so
the failed refresh is visible without discarding usable cached data.

Cache freshness uses the recorded fetch time, so copying an old cache does not
make it fresh. Legacy caches without a fetch timestamp use file modification
time. Corrupt caches, invalid/future timestamps, and caches below the current
profile's minimum are not considered fresh. Sync rejects an undersized cache
before changing native settings or disabling channels; refresh it or explicitly
adjust the profile's minimum first.

Each playlist sync uses one validated source snapshot for preparation and matching.
Fresh-cache refreshes also read the snapshot once. Within an action, parsed country
rules are shared, and guide maps, alias preparation and matching indexes reuse
their most recent result when the complete inputs are unchanged. Source files,
guide rows and selected alias sets are read again for the next playlist; edits
invalidate preparation even when timestamps stay the same. Ownership checks and
each profile's minimum channel count still apply. Reuse does not persist across
actions or retain every playlist's preparation in memory.

Alias validation batches target reads by guide within each playlist, including
targets shared by custom and bundled sets. Matching reuses normalized channel
names and skips fuzzy scoring for guide-only fallbacks, which require an exact
or structured match.

Channel preparation loads only the fields needed for matching, reporting and
concurrent-edit checks. Availability reports share failover summaries between
channel status and source coverage. Setup checks reuse the validated source
snapshot for freshness and read scrubber columns once per apply operation;
subsequent operations read current data again. Auto-merge audits scan channel
records in batches of 500 while keeping duplicate-key counts across batches.

Publishing uses the host's shared cache lock and compares the cache snapshot from
before fetching. If another refresh has published a snapshot in the meantime,
the slower run reuses that snapshot instead of overwriting it. A reused snapshot
does not generate a source recovery notice. Temporary files are cleaned up when
cache publication fails. Setup checks also deduplicate shared fetch failures and
stop when cancelled, without trying every remaining profile against a failed
provider.

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

## Channel handling

| Setting | Default | Use |
| --- | --- | --- |
| Programme guide (EPG) | required | Select a guide with channel numbers that match the lineup. GuideLink uses it to validate matches and assign consistent guide identities. |
| Include stream-health evidence in reports | `true` | Include available stream-check history in channel availability and repair reports. This does not enable, disable or start stream checks. |
| Match groups starting with | empty | Enter group-name prefixes, such as UK&#124;, or leave blank for no prefix restriction. Lineup groups are also included when Use lineup groups is on. |
| Skip matching groups — name patterns | empty | Skip matching channels in groups whose names match these regular expressions. These channels can still be disabled by the unmatched-channel policy; use Exceptions to leave them unchanged. |
| Groups to leave unchanged | empty | Enter exact group names. GuideLink leaves their matching, metadata and channel/group cleanup unchanged. These exceptions do not undo earlier changes or stop playlist imports and stream checks. |
| Groups to leave unchanged — name patterns | empty | Leave matching groups and their channels unchanged during GuideLink matching and cleanup. Enter one regular expression per tag. |
| Channels to leave unchanged — name patterns | empty | Leave channels with matching titles unchanged during GuideLink matching and cleanup. Enter one regular expression per tag. |
| Use lineup groups | `true` | Move matched channels into the lineup's groups and maintain their group order. When off, GuideLink leaves group assignments and group setup unchanged. |
| Disable channels without a confirmed match | `false` | When you run Run Sync, or a scheduled sync applies changes, disable unconfirmed channels, including review suggestions and excluded channels. Exceptions below are kept. With Use lineup groups on, also disable eligible provider groups left empty or containing only channels disabled by this policy. Turning this off does not re-enable channels or groups. |
| Channel logos | Keep each channel's preference | Programme guide logos enables **Set preferred icon to EPG** on the managed EPG map, including disabled backups. Stream provider logos resets matched channels to provider logos. Custom logo overrides are kept; missing guide logos fall back to provider logos. |

The disabling policy also blocks native auto-merge from re-enabling unconfirmed
channels. It runs only after successful metadata writes; failure or cancellation
skips cleanup. Turning it off does not re-enable channels or groups. Explicit
exceptions keep matching, metadata and cleanup unchanged, including any existing
merge restriction. They do not stop other m3u-editor jobs.

Regex fields accept one pattern per tag, or one per line in a text payload.
Commas inside patterns are preserved, including quantifiers such as `{1,3}`.
Invalid patterns stop sync before native settings or channel writes. Managed
category names use exact case/whitespace-normalized identity: a category named
`Sports` does not implicitly include `Sports Extra`. Explicit prefix settings
retain their prefix behavior.

Duplicate guide records and conflicting number labels must be resolved before
sync. An existing stream ID/channel-number fingerprint does not override a
contradictory timeshift, numbered service, or name identifying another service.
Those conflicts require review.

Availability reports always include disabled live channels and attached failover
coverage. Repair reports also always inspect failovers. Failover
totals include hidden backups, but enabled-coverage counts still use the channel
enabled flag; they are not a measurement of current stream health. Backup
counts use unique channel IDs, so distinct streams with the same title are counted
separately and shared references are counted once. VOD, self-references and
another user's channels cannot establish live failover coverage.
Source coverage uses the same matching thresholds as sync. Only accepted
matches count towards coverage; uncertain suggestions are listed separately as
review candidates. An enabled suggestion cannot hide a confirmed disabled match.
Sync summaries likewise count each source once, separating confirmed matches,
review-only suggestions and sources with no match.

New defaults do not overwrite explicitly saved profile choices. Change a profile
in **Data → Profiles**, then use Preview Sync / Run Sync to apply its settings.

Sync inspects enabled and disabled live channels. It updates accepted matches
and restores their merge eligibility, then selects usable primaries and hides
backups when **Hide backup entries** is on. This also recovers standalone matches
and channels manually disabled or marked non-mergeable. Known-dead streams stay
off until a later check succeeds; unchecked streams can participate. Managed
groups are custom live groups so native playlist import cleanup retains them.
Group identity ignores case and whitespace only: provider prefixes and quality
labels are not stripped. Imported provider groups are not adopted as managed
groups. The first sync after upgrading can protect or restore legacy unimported
groups and move matched channels away from older deleted duplicates.
Equivalent source category names share one group plan, retaining the first
spelling and avoiding duplicate groups or repeated sort changes. Blank category
names do not create groups. Exact and regex group-preserve rules also protect
group order and custom flags: preserved categories are not created, restored,
or used to move matched channels into another group.
Group creation rechecks existing managed groups under a playlist lock, so
overlapping create plans reuse the same group. Channel writes recheck the target
playlist and compare planned metadata fields against their original values.
Already-applied changes are skipped. If a field to be written was edited to a
different value after planning, that channel is left untouched and the run reports
a conflict; rerun sync to plan against the latest state. As with other channel
write failures, quarantine is skipped for that run. Numeric-looking stream IDs
remain exact strings, including leading zeros. Provider names, IDs and group
membership are rechecked before metadata or quarantine writes. Renamed target
groups are not reused by stale plans. Replaying a completed quarantine or release
does not count it as another change. Standalone native setup actions also reload
and lock the playlist before applying and saving their audit results.

Preserve rules take precedence over every exclusion. Quarantine uses matching
decisions, independently of whether a channel's metadata changed, so an unchanged
accepted match is not disabled on the next run. Preview results include each
planned enabled/merge-eligibility change and provider-group disable. Matches
needing review keep their review results while being disabled. Provider groups
containing preserved channels or confirmed matches that stay in that group are
left enabled; custom, merged and VOD groups are untouched. Group cleanup runs
only after channel writes, quarantine and matched-channel recovery succeed.
Switching the option off does not automatically re-enable unconfirmed channels
or provider groups. Confirmed matches are recovered independently of this option.

New channels imported with the native default enabled are evaluated on the next
GuideLink sync, including channels in disabled provider groups. The
`playlist.synced` hook performs availability reporting, so it does not apply this
cleanup by itself. Channels may remain enabled until Run Sync or a scheduled sync applies changes.
Preview Sync reports the proposed cleanup without disabling channels.

The internal
`plugin_guidelink_quarantines` table records prior merge preferences. Switching
quarantine off restores the prior preference for still-unconfirmed channels
without enabling them. A later accepted match clears quarantine and participates
in primary/backup selection regardless of its previous manual flags. Channels covered by preserve
rules remain untouched, including any earlier quarantine flags.
Availability repair reports quarantined channels as **keep_disabled** and does
not re-enable them, even when an existing failover is enabled.
Quarantine also removes incoming and outgoing backup links for that channel,
so playback cannot reach it through an old primary. Previously quarantined
channels with leftover links are cleaned on the next applying sync. Valid hidden
backups are kept; a later confirmed match can rebuild its backup relationships.
Before each repair write, GuideLink locks the primary and rechecks quarantine,
playlist ownership and enabled failover coverage. Changed candidates are skipped
with a reason in the results. Cancellation preserves completed repairs and their
results, marks the run cancelled, and does not mark untouched rows as repaired.
A failed repair write is reported alongside successful repairs rather than losing
the partial run history. Cancellation is checked before actions, during matching
and source coverage, before each fetch/retry, and after acquiring write locks.
An already-running native host merge cannot be interrupted inside its own job;
cancellation is checked before invoking it and again when it returns.

## Playlist Layout

In **Data → Profiles → Channel handling**, **Manage playlist layout & imports** is on by default. The defaults keep group/channel order, disable
automatic numbering and enable newly imported live channels. Explicitly saved
profile choices take precedence; profiles missing these options inherit the new
defaults on their next applying sync. Saving a profile does not itself modify
the playlist; use Preview Sync, then Run Sync.

| Profile setting | Default | Effect while layout management is enabled |
| --- | --- | --- |
| Manage playlist layout & imports | `true` | On Run Sync or a scheduled sync that applies changes, manage playlist ordering, numbering and new live imports. Turn off automatic channel/group sorting and forced numbering; apply the choices below. Ordering and numbering affect the whole playlist, including VOD. Turning this off keeps the last applied settings. |
| Playlist order | Group order, then channel order | Output a single list in channel-number order, or keep groups together with channels ordered inside each group. |
| Automatic numbers for unnumbered channels | Off | Control automatic numbering for channels without a saved number. Existing guide numbers and the playlist's starting number are kept. Number-based TVG IDs may still require a number when this is off. |
| Enable newly imported live channels | `true` | While Manage playlist layout & imports is on, enable live channels discovered in future provider imports. This import setting does not change existing channels. Run Sync separately recovers confirmed matches and applies the unmatched-channel policy. |

Installing GuideLink after creating or importing a playlist is supported.
Installation creates plugin tables, and the Playlists screen prefills disabled,
unassigned links to existing playlists. Neither step changes their settings or
channels. Choose and enable a profile assignment, then apply a sync to use its
defaults. The import hook reports availability; it does not apply these settings.
Channels imported while the native Enable New Live Channels setting was off are
still evaluated by GuideLink. A successful sync can recover confirmed matches as
primaries or hidden backups. Enabling future imports alone does not change them.

The ordering and numbering settings affect the **whole assigned playlist**,
including VOD and channels outside GuideLink's matching scope. The new-channel
toggle affects live imports only. Backup playlists and custom/merged
output playlists have their own settings and are not changed by this option.
The native starting number and TVG ID output choice remain unchanged; choosing
number-based TVG IDs in m3u-editor can still number channels without a number.

Preview Sync records current values, desired values and reasons in
`playlist_layout_audit` results. Run Sync applies only differences, in the same
transaction as auto-merge and scrubber configuration, before channel writes.
Layout and auto-merge attributes are saved together so native playlist update
hooks run once, with the complete configuration. An unchanged playlist is not
saved again.
Later runs restore managed values if they were edited directly in m3u-editor.
Turning management off stops enforcement and leaves the last applied values in
place; it does not restore earlier settings.

The setup checklist and sync reports identify enabled native Sort Alpha rules
targeting live channels, including their rule number, group selection and sort
column. These can overwrite guide order after import. Review the identified
rules in the playlist settings and disable or adjust them; GuideLink reports
them without modifying or deleting them. Disabled rules and VOD/series-only
rules are not flagged. Preview and checklist runs never write native settings.
Unresolved conflicts remain warnings on later syncs even when the managed
settings already match the profile.

## Backups & stream health

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
| Preferred stream playlist | assigned playlist | Prefer primary streams from this playlist. Leave blank to use each assigned playlist. Catch-up and resolution preferences can take priority. |
| Backup playlists | empty | Choose additional playlists in priority order for backup streams. Leave empty to use channels from the assigned playlist only. |
| Hide backup entries | `true` | Keep backup streams available for failover while disabling their separate playlist entries. Applies during GuideLink sync recovery and native backup processing. |
| Only process newly imported channels | `false` | Limit backup processing to channels marked as new by m3u-editor. Leave off to include existing eligible channels too. |
| Reconsider existing backup assignments | `true` | Allow existing backup streams to be reconsidered during the next merge. The new-channels-only restriction still applies when enabled. |
| Match backup streams by | Stream ID | Choose the identifier m3u-editor uses to group matching streams. Stream ID is the normal choice for GuideLink live channels; TMDB ID requires matching TMDB metadata. |
| Prefer higher resolution | `true` | Prefer higher-resolution streams when choosing the primary stream. Run Sync also enables native live-stream metadata probing after provider imports. Turning this preference off leaves the probing setting unchanged. GuideLink sync uses saved statistics; separate native merge jobs may probe when needed. |
| Prefer catch-up support | `true` | Prefer eligible streams with catch-up support before applying resolution and playlist preferences. |
| Avoid primary streams that failed checks | `true` | Exclude failed streams during native primary selection. GuideLink sync recovery always keeps known-dead entries off. Uses saved health results; does not start a new check. |
| Stream checker | empty | Leave blank for GuideLink to create or reuse its checker for each assigned playlist. A selected checker must belong to that playlist. GuideLink only changes checkers it manages; edit a user-managed checker in m3u-editor's Channel Scrubbers screen. |
| Avoid primary streams from disabled groups | `true` | Exclude channels in disabled groups when choosing a primary stream. |
| Check streams after playlist import | `true` | Run the managed stream checker after m3u-editor imports or refreshes the provider playlist. This is separate from a GuideLink metadata sync. |
| Include disabled channels in checks | `true` | Check disabled live channels as well as enabled ones, so backup selection can use their latest health results. Checking a recovered stream does not directly re-enable its entry. |
| Disable streams that fail checks | `true` | Let the managed checker disable channel entries whose streams fail its check. When off, it still records the results without disabling those entries. |
| Rebuild backups after a successful scan | `true` | After the checker completes successfully, rerun m3u-editor's backup selection using the updated health results. |
| Stream-check method | HTTP response check | Use an HTTP response check for a quick availability check, or FFprobe to inspect the media stream. |
| Stream-check timeout (seconds) | `10` | Maximum time allowed for each stream check. Longer timeouts give slow streams more time to respond and can make a scan take longer. |

When **Prefer higher resolution** is enabled, GuideLink manages **Probe Live
streams after sync** on the assigned playlist. Preview Sync reports the proposed
change; Run Sync enables the native setting and restores it on later runs if
edited directly. Turning the preference off stops enforcement and leaves the
current probing setting in place. No additional profile switch is required.

This collects codec, resolution and bitrate metadata after a native provider
import; it is separate from the stream checker's live/dead checks. GuideLink's
metadata sync does not start probing. The existing only-unprobed, include-disabled,
per-channel opt-out, timeout, batching and VOD probing settings remain unchanged,
as do the settings on additional backup playlists. Enabled-only automatic probing
skips hidden backups; native merge jobs can obtain missing statistics for eligible
candidates when comparing resolution. GuideLink recovery continues using saved
statistics, so new probe results affect a subsequent selection.

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
slots for existing services are skipped and fuzzy fallback is disabled. These
channels use the managed **Other** group so a confirmed guide-only match does
not keep an otherwise quarantined provider group enabled. The existing group
management opt-out and preserve rules still apply. Native playlist mode remains
limited to its chosen source playlist; empty native categories retain their
current groups.

Channel-specific aliases belong to selected sets and target guide IDs. Bundled
entries live in the installed JSON; custom entries live in the database.
The bundled Sky UK dataset includes established old names as alternative names.
General formatting and regional rules remain in the country JSON files.
Decorative playlist headings such as `#### SPORTS ####` are excluded generically.
Fuzzy matching preserves distinguishing numbers and plus
suffixes. Channels missing from both the source and selected numbered guide
cannot be made valid matches by lowering thresholds. Their metadata stays unchanged;
the optional quarantine policy disables unconfirmed channels except preserved
channels and unresolved channels affected by a withheld bundled alias.

EPG association follows two stages. GuideLink matches the playlist name to the
source, assigns its channel number, and saves the selected numbered guide's ID
in `stream_id_custom` (for example, `303.uk`). After successful metadata and
channel-policy writes, GuideLink configures a recurring native **EPG Map** and
queues m3u-editor's mapper. The native mapper prioritizes that ID over names and
writes the EPG association. GuideLink does not write `epg_channel_id` itself.

**Programme guide logos** sets the map's **Set preferred icon to EPG** option.
The native mapper applies that preference when it links each channel. **Keep each
channel's preference** turns the option off and retains existing choices.
**Stream provider logos** also turns it off and resets confirmed channels to the
provider preference during metadata sync, because turning off the native option
alone does not reset a previously selected EPG preference. Custom logo overrides
and the native fallback to provider logos are retained in all modes.

The managed map covers the whole playlist, with **Overwrite** and **Recurring**
on, **Skip channels without EPG ID** off, and **Prioritize name/display name
matching** off. Native mapping uses saved custom or provider IDs first, with the
host's name matching as fallback. It does not compare playlist channel numbers
directly: GuideLink supplies the corresponding XMLTV IDs during lineup sync.
The host excludes VOD and channels with native EPG mapping disabled. GuideLink's
lineup matching exceptions do not restrict this separate native operation.
Other user-created maps and additional native matching options are preserved.

Preview reports the map settings and scope without creating or running a map.
Run Sync reports **queued** or **processing**, not EPG completion; use native
**EPG Maps** for mapping progress and errors. Recurrence reruns the native mapper
after EPG refresh, including channels imported since the last GuideLink sync.
Every successful Run Sync also queues native mapping, even when metadata and map
settings are unchanged. GuideLink does not inspect or repair individual EPG links
or logo preferences. A busy native map must finish before its settings can change;
an unchanged active map is reported as processing without another dispatch.

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
The November dataset also recognises the verified Animation/Christmas and
Halloween/Bourne/Batman/Mad Max/The Mummy/Mission Impossible cinema names,
Nick Alvinnn!!!/Nick SpongeBob, and New Vision TV/ARY World, based on Sky's
[October](https://helpforum.sky.com/t5/What-s-On/Changes-to-the-TV-Guide-October-2026/td-p/5379297)
and [November announcements](https://helpforum.sky.com/t5/What-s-On/Changes-to-the-TV-Guide-November-2026/m-p/5411427).
These explicit aliases still validate the guide's named target before renaming
channels. A number alone does not establish a new name match; maintaining an
existing numbered guide association is a separate native EPG operation.
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
| Minimum name similarity | `90` | Minimum similarity for an approximate name comparison to become a candidate. Higher values accept fewer name variations; lower values allow more candidates. |
| Minimum automatic-match score | `90` | Minimum score for accepting scored matches automatically. Higher values can send more candidates for review; exact matches and other identity rules follow their own checks. |
| Required lead over next candidate | `12` | Normally require the best approximate match to lead the next candidate by this many points. Higher values send more close matches for review. |
| Word-order score allowance | `10` | Allow the supporting word-order comparison score to fall this many points below Minimum name similarity. Higher values make some approximate matching paths more permissive. |
| Minimum words for derived name variants | `2` | Use this word count when checking automatically derived name variants. Higher values limit variants that are too short to identify a channel reliably. |
| Minimum characters for derived name variants | `4` | Use this character count to guard shortened or derived name variants. Higher values reject more short variants and broadcaster-prefix shortcuts. |
| Minimum lineup-name words for word-set matching | `2` | Require this many words in the lineup name before comparing shared word sets. Higher values limit this method to longer lineup names. |
| Minimum full-name length ratio | `0.7` | For full-name comparison, require the shorter name to be at least this fraction of the longer name. Higher values allow less difference in length; 0.70 means 70%. |
| Full-name score without supporting word match | `90` | Require this full-name score when the supporting word-order score is too low. Higher values make this route stricter; Minimum name similarity still applies. |
| Minimum partial-name length ratio | `0.65` | Before comparing part of a name, require the shorter name to be at least this fraction of the longer name. Higher values allow less difference in length; 0.65 means 65%. |
| Minimum partial-name length | `5` | Require both names to contain at least this many characters before partial-name comparison. Higher values prevent this method from using very short names. |
| Supporting full-name score for partial matches | `80` | Require this full-name similarity before accepting evidence from a partial-name comparison. Higher values make partial matching stricter. |
| Short-word safeguard length | `2` | Treat abbreviations up to this length more cautiously. Higher values extend the safeguard to longer words and can send more ambiguous names for review. |
| Minimum similarity for spelling differences | `60` | Require this similarity before treating differing words as a small spelling variation. Higher values make the distinguishing-word check stricter. |

The matching scores are points, not probabilities. A score of 90 does not mean
a 90% chance of the correct identity. Minimum automatic-match score is not a gate
on every confirmed match: exact matches and identity checks have their own rules.
Required lead uses word-order-independent scores; a sibling-channel rule can
accept a smaller lead when the name supplies the distinguishing evidence.

The supporting word-order floor is `max(Minimum name similarity − Word-order
score allowance, 0)`. Full-name comparison can pass its support guard with either
that floor or the full-name score. Partial-name comparison needs its supporting
full-name score and the word-order floor. Length ratios remain decimals: `0.70`
means 70%, not a stored value of 70.

Derived-name word/character safeguards do not apply to explicit alias entries.
Some derived-name paths accept enough words **or** characters, while a broadcaster
prefix shortcut requires enough remaining characters. Short-word safeguards and
identity checks can still send a high-scoring candidate for review. Preview after
changing matching rules; lowering a score cannot supply a missing guide identity.

## Actions

Every action records its run status and logs. The tables below distinguish those
records from changes to playlist data, native settings, and saved report rows.

| Action | Writes data | Use |
| --- | --- | --- |
| Check Channels | Run results only | Reports disabled primaries, failover coverage, scrubber context, and source coverage using the playlist's assigned profile. |
| Preview Sync | Cache and run results only | Previews native settings, matching, groups, logos and quarantine, and counts confirmed recovery candidates. Final primary selection uses current health and native state after metadata is applied. |
| Run Sync | Native settings, metadata/groups, quarantine, primary/backup state, cache and run results | Applies the profile, metadata and quarantine, then recovers confirmed matches using native primary selection and failover records. Requires confirmation; does not start a scan. |
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

Native auto-merge can restore an existing failover/master when its stream becomes
live again. GuideLink's next successful sync also restores confirmed matches
without existing failover relationships. Manual enabled/merge flags do not
override the assigned profile's matching policy; use profile Exceptions to keep
specific target channels unchanged.

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
