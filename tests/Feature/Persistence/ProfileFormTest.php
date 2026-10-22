<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Persistence;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Livewire\PluginTableInline;
use App\Models\ChannelScrubber;
use App\Models\Playlist;
use App\Models\User;
use App\Plugins\PluginIntegrityService;
use App\Plugins\PluginManager;
use App\Plugins\PluginSchemaMapper;
use App\Plugins\PluginUiRequestCache;
use App\Plugins\PluginUiTableRegistry;
use AppLocalPlugins\GuideLink\Services\ProfileAliasResolver;
use AppLocalPlugins\GuideLink\Services\ProfileRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class ProfileFormTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_saving_the_grouped_form_preserves_customized_values_in_both_source_modes(): void
    {
        [$plugin, $profile, $settings] = $this->profileFormFixture();

        foreach (['scraper_cache', 'native_playlist'] as $sourceMode) {
            $settings['source_mode'] = $sourceMode;
            $profile->update(['settings' => $settings]);

            $component = Livewire::test(PluginTableInline::class, ['record' => $plugin, 'tableId' => 'profiles'])
                ->mountTableAction('edit', $profile)
                ->assertTableActionDataSet(['name' => 'Customized profile', 'settings' => $settings])
                ->assertFormFieldExists('settings.playlist_enable_new_channels')
                ->assertFormFieldExists('settings.scraping_api_key', fn (TextInput $field): bool => $field->isPassword() && $field->isPasswordRevealable());

            if (! method_exists(PluginManager::class, 'fieldVisible')) {
                $component->assertFormFieldIsVisible('settings.source_playlist_id')
                    ->assertFormFieldIsVisible('settings.scraping_api_key')
                    ->assertFormFieldIsVisible('settings.scrubber_recurring');
            }

            $component->callMountedTableAction()->assertHasNoTableActionErrors();

            $this->assertEquals($settings, Arr::only($profile->refresh()->settings, array_keys($settings)));
        }
    }

    public function test_relocated_source_fields_keep_dependency_resets_and_unrelated_unsaved_values(): void
    {
        [$plugin, $profile] = $this->profileFormFixture();

        $component = Livewire::test(PluginTableInline::class, ['record' => $plugin, 'tableId' => 'profiles'])
            ->mountTableAction('edit', $profile)
            ->set('mountedActions.0.data.settings.scraping_api_key', 'unsaved-fixture-key')
            ->set('mountedActions.0.data.settings.min_ratio_len_ratio', 0.83)
            ->set('mountedActions.0.data.settings.source_mode', 'native_playlist')
            ->set('mountedActions.0.data.settings.source_mode', 'scraper_cache')
            ->assertTableActionDataSet([
                'settings.provider' => 'sky',
                'settings.provider_source' => 'viewersvault',
                'settings.scraping_api_key' => 'unsaved-fixture-key',
                'settings.min_ratio_len_ratio' => 0.83,
            ])
            ->set('mountedActions.0.data.settings.country', 'unsupported')
            ->assertTableActionDataSet([
                'settings.provider' => null,
                'settings.provider_source' => null,
                'settings.scraping_api_key' => 'unsaved-fixture-key',
                'settings.min_ratio_len_ratio' => 0.83,
            ]);

        $component->unmountTableAction();
        $this->assertSame('fixture-secret', $profile->refresh()->settings['scraping_api_key']);
    }

    public function test_source_and_download_fields_follow_the_selected_source_without_clearing_values(): void
    {
        if (! method_exists(PluginManager::class, 'fieldVisible')) {
            $this->markTestSkipped('This host does not support optional plugin field visibility.');
        }

        [$plugin, $profile] = $this->profileFormFixture();
        $component = Livewire::test(PluginTableInline::class, ['record' => $plugin, 'tableId' => 'profiles'])
            ->mountTableAction('edit', $profile)
            ->assertFormFieldIsHidden('settings.source_playlist_id')
            ->assertFormFieldIsHidden('settings.scraping_api_key')
            ->set('mountedActions.0.data.settings.scraping_api', 'scrapedo')
            ->assertFormFieldIsVisible('settings.scraping_api_key')
            ->set('mountedActions.0.data.settings.scraping_api_key', 'unsaved-key')
            ->set('mountedActions.0.data.settings.source_mode', 'native_playlist')
            ->assertFormFieldIsVisible('settings.source_playlist_id');

        $instance = $component->instance();
        $html = $instance->getSchema($instance->getMountedActionSchemaName())->toHtml();
        $this->assertStringNotContainsString('Website lineup', $html);
        $this->assertStringNotContainsString('Downloads &amp; source checks', $html);
        $component->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('unsaved-key', $profile->refresh()->settings['scraping_api_key']);
        $this->assertSame('sky', $profile->settings['provider']);
    }

    public function test_checker_controls_follow_actual_ownership_and_retain_hidden_settings(): void
    {
        if (! method_exists(PluginManager::class, 'fieldVisible')) {
            $this->markTestSkipped('This host does not support optional plugin field visibility.');
        }

        [$plugin, $profile, $settings] = $this->profileFormFixture();
        $playlist = Playlist::findOrFail($settings['source_playlist_id']);
        $checker = ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'user_id' => $playlist->user_id, 'playlist_id' => $playlist->id, 'name' => 'My checker',
        ]));
        $managed = ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'user_id' => $playlist->user_id, 'playlist_id' => $playlist->id, 'name' => 'GuideLink - Managed',
        ]));
        $foreignPlaylist = Playlist::factory()->for(User::factory())->createQuietly();
        $foreign = ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'user_id' => $foreignPlaylist->user_id, 'playlist_id' => $foreignPlaylist->id, 'name' => 'GuideLink - Foreign',
        ]));
        $component = Livewire::test(PluginTableInline::class, ['record' => $plugin, 'tableId' => 'profiles'])
            ->mountTableAction('edit', $profile)
            ->assertFormFieldIsVisible('settings.scrubber_recurring')
            ->set('mountedActions.0.data.settings.scrubber_probe_timeout', 23)
            ->set('mountedActions.0.data.settings.scrubber_id', $checker->id)
            ->assertFormFieldIsHidden('settings.scrubber_recurring')
            ->assertFormFieldIsHidden('settings.scrubber_disable_dead')
            ->assertFormFieldIsVisible('settings.include_scrubber_logs')
            ->set('mountedActions.0.data.settings.scrubber_id', $managed->id)
            ->assertFormFieldIsVisible('settings.scrubber_recurring')
            ->set('mountedActions.0.data.settings.scrubber_id', $foreign->id)
            ->assertFormFieldIsHidden('settings.scrubber_recurring')
            ->set('mountedActions.0.data.settings.scrubber_id', $checker->id)
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame(23, $profile->refresh()->settings['scrubber_probe_timeout']);
        $this->assertSame(false, $profile->settings['scrubber_recurring']);
        $this->assertSame($checker->id, $profile->settings['scrubber_id']);
    }

    public function test_rendering_the_profile_reuses_integrity_scans_and_playlist_lookups(): void
    {
        if (! method_exists(PluginManager::class, 'fieldVisible')) {
            $this->markTestSkipped('This host does not support the optional plugin UI request cache.');
        }

        [$plugin, $profile] = $this->profileFormFixture();
        $component = Livewire::test(PluginTableInline::class, ['record' => $plugin, 'tableId' => 'profiles'])
            ->mountTableAction('edit', $profile);
        $integrity = new class extends PluginIntegrityService
        {
            public int $scans = 0;

            public function hashesForPlugin(string $pluginPath, ?string $entrypoint = null): array
            {
                $this->scans++;

                return parent::hashesForPlugin($pluginPath, $entrypoint);
            }
        };
        app()->instance(PluginIntegrityService::class, $integrity);
        foreach (['validation', 'options', 'columns', 'visibility'] as $group) {
            app(PluginUiRequestCache::class)->forget($group);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $instance = $component->instance();
        $instance->getSchema($instance->getMountedActionSchemaName())->toHtml();

        $this->assertSame(1, $integrity->scans);
        $playlistQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "playlists"'));
        $this->assertCount(1, $playlistQueries);
        $columnQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'pragma_table_xinfo'));
        $this->assertSame($columnQueries->count(), $columnQueries->unique('query')->count());
    }

    private function profileFormFixture(): array
    {
        Bus::fake();
        Http::preventStrayRequests();
        [$context, $playlist] = $this->context(applyPluginSchema: true);
        $context->user->update(['is_admin' => true]);
        $this->actingAs($context->user);

        // Trust this local fixture through the host so dynamic option providers run normally.
        $context->plugin->forceFill([
            'plugin_id' => 'guidelink',
            'source_type' => 'bundled',
            'path' => dirname(__DIR__, 3),
        ])->save();
        $plugin = app(PluginManager::class)->trust($context->plugin);
        $guide = $this->guideEpgId($context);
        $backup = Playlist::factory()->for($context->user)->createQuietly();
        $sets = [];
        foreach (['First aliases', 'Second aliases'] as $name) {
            $sets[] = DB::table(ProfileAliasResolver::SETS)->insertGetId([
                'extension_plugin_id' => $plugin->id,
                'name' => $name,
                'country' => 'uk',
                'guide_epg_id' => $guide,
                'enabled' => true,
            ]);
        }

        $fields = collect($plugin->schema_definition['ui_tables'])->firstWhere('id', 'profiles')['fields'];
        $defaults = Arr::undot(app(PluginSchemaMapper::class)->defaultsForFields($fields));
        $settings = array_replace($defaults['settings'], [
            'guide_epg_id' => $guide,
            'source_playlist_id' => $playlist->id,
            'scraping_api_key' => 'fixture-secret',
            'alias_set_ids' => array_reverse($sets),
            'auto_merge_failover_playlist_ids' => [$backup->id, $playlist->id],
            'playlist_layout_managed' => false,
            'playlist_output_order' => 'number',
            'playlist_missing_numbers' => 'preserve',
            'playlist_enable_new_channels' => false,
            'disable_other_groups' => true,
            'include_managed_groups' => false,
            'preserve_groups' => ['LOCAL', 'Cameras'],
            'preserve_group_name_regex' => ['^MY [0-9]{1,3}$'],
            'preserve_channel_title_regex' => ['^Door Camera$'],
            'include_group_prefixes' => ['UK|'],
            'exclude_group_regex' => ['^TEST'],
            'min_ratio_len_ratio' => 0.81,
            'min_partial_len_ratio' => 0.72,
            'min_auto_margin' => 17,
            'scrubber_recurring' => false,
            'scrubber_disable_dead' => false,
            'scrubber_check_method' => 'ffprobe',
            'scrubber_probe_timeout' => 19,
        ]);
        $profile = app(PluginUiTableRegistry::class)->newModel($plugin, ProfileRepository::PROFILES_TABLE)
            ->newQuery()->create([
                'extension_plugin_id' => $plugin->id,
                'name' => 'Customized profile',
                'enabled' => true,
                'settings' => $settings,
            ]);

        return [$plugin, $profile, $settings];
    }
}
