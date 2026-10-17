# Installing GuideLink

GuideLink is installed through m3u-editor's reviewed plugin install flow.
Discovery does not imply trust: the host validates the plugin, an admin reviews
the staged install, and trust pins the reviewed file hashes.

This guide follows the host plugin lifecycle documented in
`m3u-editor/docs/plugin-dev.md`: a plugin must be installed, enabled, valid,
trusted, and integrity-verified before it can execute.

For Docker deployments, any local path used below must be visible inside the
m3u-editor application container.

GuideLink uses the plugin id `guidelink`. Updates preserve existing profiles,
playlist assignments, and custom aliases; fresh installations need initial setup.

## Prerequisites

- m3u-editor with the trusted-local plugin system enabled.
- Host support for plugin-backed dynamic select options.
- m3u-editor native recovery schema current enough for GuideLink's
  scrubber/failover integration:
  `channels.last_scrubber_live`,
  `channel_scrubbers.protect_failover_channels`, and
  `channel_scrubbers.rebuild_failovers_after_scan`.
- Admin access to the plugin UI, or access to `php artisan` for the CLI flows.
- A plugin source directory, plugin archive, browser-uploaded archive, or
  published release asset.
- ClamAV or the host's configured plugin scan driver for normal reviewed
  installs.

## Validate Before Installing

From the plugin root:

```bash
php scripts/validate-plugin.php
```

If host PHP is unavailable, validate from the workspace Podman harness:

```bash
cd ../podman
./bin/compose exec m3u-editor-plugin-dev \
  php /var/www/html/plugins/guidelink/scripts/validate-plugin.php
```

## Install From A Local Directory

Use this when the m3u-editor host/container can read the plugin directory
directly:

```bash
php artisan plugins:stage-directory /absolute/path/to/guidelink
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

In Docker, `/absolute/path/to/guidelink` must be a path inside the
container, not only a path on your workstation.

## Install From A Browser Upload

Use this for private Docker installs when the plugin directory is not mounted
into the application container.

1. Download `guidelink.zip` and `SHA256SUMS` from the release. Verify the ZIP:

   ```bash
   sha256sum --ignore-missing -c SHA256SUMS
   ```

   Confirm the output includes `guidelink.zip: OK`. To build from source, run
   `python3 scripts/package-release.py` from the plugin root; the archive and
   checksums are written to `dist/v<version>/`.

2. Open `Extensions -> Plugin Installs`.
3. Choose **Upload Plugin Archive**.
4. Upload `guidelink.zip`.
5. Run the scan and approve/trust actions from the review page.

The host stores the uploaded archive on private local storage long enough to
stage and review it. Keep m3u-editor's `storage/app` on persistent storage if
plugin install reviews need to survive container restarts.

## Install From A Local Archive

Use this when the archive is already visible to the host/container:

```bash
php artisan plugins:stage-archive /absolute/path/to/guidelink.zip
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

## Install From A GitHub Release Asset

Use this once a release archive and checksum are published:

```bash
php artisan plugins:stage-github-release \
  https://github.com/<owner>/<repo>/releases/download/<tag>/guidelink.zip \
  --sha256=<published-sha256>
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
```

## Verify The Install

After approval/trust:

```bash
php artisan plugins:discover
php artisan plugins:validate guidelink
php artisan plugins:doctor
```

In the UI:

1. Open `Extensions -> Extensions`.
2. Confirm **GuideLink** is installed, trusted, valid, and enabled.
3. Run **Plugin Diagnostics** from the advanced action id only if the install
   needs troubleshooting.
4. Create a profile in **Data → Profiles**, assign and enable it in **Data → Playlists**,
   then use **Preview Sync** and **Run Sync**.

## Update An Existing Install

Stage the new directory or archive using the same install flow. If the plugin id
matches an existing install, approving the install review automatically replaces
the plugin files; there is no separate **Replace** option. Trust the updated files after review.

Before approval, pause GuideLink's scheduled and playlist-sync triggers and let
active GuideLink runs finish. After approval/trust, recycle the host's queue
workers before resuming those triggers or starting another run. m3u-editor's
Horizon workers retain loaded plugin PHP classes across jobs; installing new
files does not replace the classes already loaded in a worker.

For the standard supervised m3u-editor container, run inside the application
container:

```bash
php artisan horizon:terminate --wait
php artisan horizon:status
```

The process supervisor starts a fresh Horizon instance after the old one exits.
Wait for that restart to complete and confirm Horizon is running before resuming
GuideLink. This allows active jobs to finish and keeps pending jobs queued. Run
the command on each host/container running Horizon if there are several. Custom
deployments must also have a process manager configured to restart Horizon.
Avoid **Reset Queue** / `app:restart-queue` for this upgrade: that host action also
clears pending jobs.

Also reload the web PHP workers when OPcache timestamp validation is disabled
(`opcache.validate_timestamps=0`), otherwise UI requests may keep using old plugin
code. The standard PHP-FPM container supports a graceful reload with `SIGUSR2`
sent to its PHP-FPM master process. Verify the master PID in the running container
before sending the signal; do not reuse a PID from a previous deployment. Custom
web runtimes should use their own graceful reload procedure. Restarting Horizon
alone does not clear the web workers' OPcache.

### Upgrading From 0.1.0 To 0.1.1

The reviewed update adds plugin-owned quarantine and alert-state tables. Complete approval and
trust before running sync; copying PHP files alone does not install the new schema.
Native alerts default to enabled and reuse the host's configured alert channels.
Review **Settings → Native Alerts** for the enable switch and reminder interval;
see [native alert behaviour](CONFIGURATION.md#native-alerts).

1. Edit existing profiles in **Data → Profiles**. The previous quarantine toggle
   retains its saved value and is now labelled **Disable Excluded and Unmatched
   Channels**. When enabled, it also covers prefix, regex, heading, and non-linear
   exclusions, plus unmatched channels already in managed groups. Explicit preserve
   rules and matches needing review remain untouched.
2. Choose **Channel Logo Source → EPG Logos** if desired. The default keeps existing
   logo preferences; custom logo overrides retain priority.
3. Optionally enable **Let GuideLink Manage Playlist Layout** in the profile to
   protect guide order and numbers. Choose flat guide number order or grouped
   order. Existing profiles remain unmanaged until this option is enabled;
   these native settings affect the entire assigned playlist, including VOD.
4. Run **Preview Sync** and inspect **Results**, including the proposed layout and
   quarantine changes. The first apply protects or restores eligible legacy managed groups
   and repairs matched disabled backups without enabling them.
5. Run **Run Sync**, then preview again. Unchanged source and channel data should
   produce no further metadata changes. Managed groups now survive playlist import
   cleanup without being recreated.

Quarantine also blocks native auto-merge from re-enabling rejected channels.
Turning the toggle off and running sync restores the recorded merge preference
for channels not covered by preserve rules; it does not directly enable channels.

The ZIP contains the read-only bundled alias datasets. GuideLink reads those files
directly, so subsequent matching and previews use the installed defaults without
an import, database update, scheduler or lifecycle callback. Existing set IDs,
profile attachments and database-backed custom aliases stay intact.
See [alias configuration](CONFIGURATION.md#read-only-bundled-defaults).

Useful commands:

```bash
php artisan plugins:stage-directory /absolute/path/to/guidelink
php artisan plugins:scan-install <review-id>
php artisan plugins:approve-install <review-id> --trust
php artisan plugins:validate guidelink
php artisan plugins:doctor
```

## Uninstall

Use m3u-editor's plugin lifecycle actions from the UI, or use Artisan:

```bash
php artisan plugins:uninstall guidelink --cleanup=preserve
```

Use `--cleanup=preserve` unless you intentionally want plugin-owned data purged.
Preserving keeps profiles, assignments, run results, and plugin-owned cache data
available for review or reinstall, including alias sets and custom alias entries.

With preserve mode, native groups, channel metadata, and quarantine flags remain
in place for review or reinstall. To restore recorded merge preferences first,
turn off **Disable Excluded and Unmatched Channels** and run sync. Preserve rules
still take priority during sync.

Purge mode restores merge preferences recorded by GuideLink before deleting its
quarantine records. It does not enable channels or undo channel metadata, groups,
playlist settings, or scrubber setup. A channel whose merge preference was already
disabled before quarantine stays non-mergeable.

To reinstall a preserved plugin:

```bash
php artisan plugins:reinstall guidelink
```

## Troubleshooting

If the plugin does not appear:

```bash
php artisan plugins:discover
php artisan plugins:doctor
```

If validation fails, run:

```bash
php artisan plugins:validate guidelink
php scripts/validate-plugin.php
```

If sync or the advanced setup checklist reports missing native scrubber/failover
columns, update or migrate the m3u-editor host before running sync.

If a local directory install cannot find the path, confirm the path is visible
inside the m3u-editor container.

If an archive review cannot be trusted, check the configured plugin scan driver
and ClamAV status on the host.
