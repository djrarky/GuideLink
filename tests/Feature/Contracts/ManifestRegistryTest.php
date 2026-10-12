<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Contracts;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Plugin as PluginRecord;
use App\Plugins\PluginValidator;
use App\Plugins\Support\PluginSelectOptionsContext;
use AppLocalPlugins\GuideLink\Plugin as GuideLinkPlugin;
use AppLocalPlugins\GuideLink\Scrapers\ScraperRegistry;
use AppLocalPlugins\GuideLink\Sources\SourceRegistry;
use AppLocalPlugins\GuideLink\Support\CountryRulesRepository;
use AppLocalPlugins\GuideLink\Support\DynamicOptionProviders;
use AppLocalPlugins\GuideLink\Support\PluginContractValidator;
use AppLocalPlugins\GuideLink\Support\ProfileDefaults;
use AppLocalPlugins\GuideLink\Support\RegistryContractValidator;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;

class ManifestRegistryTest extends PluginFeatureTestCase
{
    public function test_plugin_contract_is_valid(): void
    {
        $errors = (new PluginContractValidator(dirname(__DIR__, 3)))->validate();

        $this->assertSame([], $errors, implode(PHP_EOL, $errors));

        $validation = app(PluginValidator::class)->validatePath(dirname(__DIR__, 3));

        $this->assertTrue($validation->valid, implode(PHP_EOL, $validation->errors));
        $this->assertSame('guidelink', $validation->pluginId);
        $this->assertSame(GuideLinkPlugin::class, $validation->manifestData['class']);
    }

    public function test_playlist_assignments_clear_prefilled_rows_instead_of_deleting_them(): void
    {
        $table = collect($this->manifest()['schema']['ui_tables'])
            ->firstWhere('id', 'playlist_assignments');

        $this->assertSame(false, $table['create']);
        $this->assertSame('clear', $table['delete_behavior']);
        $this->assertSame([
            'extension_plugin_profile_id' => null,
            'enabled' => false,
            'settings.run_sync' => true,
            'settings.run_availability' => true,
        ], $table['delete_payload']);
    }

    public function test_run_results_exports_include_diagnostic_details(): void
    {
        $table = collect($this->manifest()['schema']['ui_tables'])
            ->firstWhere('id', 'run_results');

        $this->assertSame(['csv', 'json'], $table['export_formats']);

        $columns = collect($table['columns'])
            ->pluck('name')
            ->all();

        $this->assertContains('details', $columns);
    }

    public function test_dynamic_options_match_registries(): void
    {
        $plugin = new GuideLinkPlugin;
        $record = new PluginRecord;
        $record->forceFill(['plugin_id' => 'guidelink-test']);

        $context = new PluginSelectOptionsContext(
            plugin: $record,
            user: null,
            settings: [],
            state: [
                'settings.provider' => ProfileDefaults::string('provider'),
                'settings.country' => ProfileDefaults::string('country'),
            ],
            field: [],
        );

        $this->assertSame(
            SourceRegistry::providerOptions(ProfileDefaults::string('country')),
            $plugin->selectOptions(DynamicOptionProviders::PROVIDERS, $context),
        );
        $this->assertSame(
            SourceRegistry::providerOptions(),
            SourceRegistry::providerOptions(''),
        );
        $this->assertSame(
            CountryRulesRepository::options(),
            $plugin->selectOptions(DynamicOptionProviders::COUNTRIES, $context),
        );
        $this->assertSame(
            CountryRulesRepository::options(),
            SourceRegistry::countryOptions(''),
        );
        $this->assertSame(
            SourceRegistry::sourceOptions(ProfileDefaults::string('provider'), ProfileDefaults::string('country')),
            $plugin->selectOptions(DynamicOptionProviders::SOURCES, $context),
        );
        $this->assertSame(
            ScraperRegistry::options(),
            $plugin->selectOptions(DynamicOptionProviders::SCRAPERS, $context),
        );

        $unsupportedCountry = new PluginSelectOptionsContext(
            plugin: $record,
            user: null,
            settings: [],
            state: [
                'settings.provider' => ProfileDefaults::string('provider'),
                'settings.country' => 'unsupported',
            ],
            field: [],
        );

        $this->assertSame([], $plugin->selectOptions(DynamicOptionProviders::SOURCES, $unsupportedCountry));
        $this->assertSame([], $plugin->selectOptions('unknown_options_provider', $context));
    }

    public function test_dynamic_source_options_prevent_incompatible_provider_country_choices(): void
    {
        $plugin = new GuideLinkPlugin;
        $record = new PluginRecord;
        $record->forceFill(['plugin_id' => 'guidelink-test']);

        $skyContext = new PluginSelectOptionsContext(
            plugin: $record,
            user: null,
            settings: [],
            state: [
                'settings.provider' => 'sky',
                'settings.country' => 'uk',
            ],
            field: [],
        );

        $this->assertSame(['uk' => 'UK / Ireland'], $plugin->selectOptions(DynamicOptionProviders::COUNTRIES, $skyContext));
        $this->assertSame(['sky' => 'Sky'], $plugin->selectOptions(DynamicOptionProviders::PROVIDERS, $skyContext));
        $this->assertSame([
            'viewersvault' => 'ViewersVault',
            'tvchannellists' => 'TV Channel Lists',
        ], $plugin->selectOptions(DynamicOptionProviders::SOURCES, $skyContext));

        $unsupportedProvider = new PluginSelectOptionsContext(
            plugin: $record,
            user: null,
            settings: [],
            state: [
                'settings.provider' => 'unsupported',
                'settings.country' => 'uk',
            ],
            field: [],
        );

        $this->assertSame(['uk' => 'UK / Ireland'], $plugin->selectOptions(DynamicOptionProviders::COUNTRIES, $unsupportedProvider));
        $this->assertSame([], $plugin->selectOptions(DynamicOptionProviders::SOURCES, $unsupportedProvider));

        $unsupportedCountry = new PluginSelectOptionsContext(
            plugin: $record,
            user: null,
            settings: [],
            state: [
                'settings.provider' => 'sky',
                'settings.country' => 'us',
            ],
            field: [],
        );

        $this->assertSame([], $plugin->selectOptions(DynamicOptionProviders::PROVIDERS, $unsupportedCountry));
        $this->assertSame([], $plugin->selectOptions(DynamicOptionProviders::SOURCES, $unsupportedCountry));
    }

    public function test_country_rule_files_are_optional_but_validated_when_present(): void
    {
        $root = sys_get_temp_dir().'/guidelink-country-pack-'.bin2hex(random_bytes(6));
        $countryDirectory = $root.'/src/Sources/Countries/USA';
        $providerDirectory = $countryDirectory.'/SkyUSA';

        try {
            File::makeDirectory($providerDirectory, 0777, true);
            File::put($countryDirectory.'/country.json', '{"key":"us","name":"United States"}');
            File::put($providerDirectory.'/provider.json', '{"provider":"sky","label":"Sky USA","sources":["viewersvault"]}');

            $this->assertSame([], $this->countryPackErrors($root));

            File::put($countryDirectory.'/filters.json', '{');
            $errors = $this->countryPackErrors($root);

            $this->assertStringContainsString(
                'Country pack [USA] filters rules',
                implode(PHP_EOL, $errors),
            );
        } finally {
            File::deleteDirectory($root);
        }
    }

    /**
     * @return array<int, string>
     */
    private function countryPackErrors(string $root): array
    {
        $validator = new RegistryContractValidator($root);
        $method = new ReflectionMethod($validator, 'validateCountryPacks');
        $method->setAccessible(true);

        $errors = [];
        $method->invokeArgs($validator, [&$errors]);

        return $errors;
    }
}
