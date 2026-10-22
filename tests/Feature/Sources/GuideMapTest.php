<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Sources;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;
use AppLocalPlugins\GuideLink\Services\GuideMapService;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GuideMapTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_unmapped_guide_is_rejected(): void
    {
        [$context] = $this->context();
        $epg = Epg::factory()->for($context->user)->createQuietly();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no usable channel-number-to-stream-ID mappings');

        (new GuideMapService)->resolve(['guide_epg_id' => $epg->id], $context);
    }

    public function test_another_users_guide_is_rejected(): void
    {
        [$context] = $this->context();
        $epg = Epg::factory()->createQuietly();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found or is not available');

        (new GuideMapService)->resolve(['guide_epg_id' => $epg->id], $context);
    }

    public function test_numbered_guide_resolves_stream_ids(): void
    {
        [$context] = $this->context();
        $epgId = $this->guideEpgId($context);
        $guide = (new GuideMapService)->resolve(['guide_epg_id' => $epgId], $context);

        $this->assertSame([101 => 'bbc.one'], $guide['map']);
        $this->assertSame($epgId, $guide['epg']['id']);
    }

    public function test_duplicate_guide_records_cannot_select_an_arbitrary_epg_channel(): void
    {
        [$context] = $this->context();
        $epgId = $this->guideEpgId($context);
        EpgChannel::factory()->createQuietly([
            'epg_id' => $epgId, 'user_id' => $context->user->id,
            'channel_id' => 'bbc.one', 'display_name' => '101', 'name' => 'Different service',
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate records');
        (new GuideMapService)->resolve(['guide_epg_id' => $epgId], $context);
    }

    public function test_conflicting_number_labels_are_rejected(): void
    {
        [$context] = $this->context();
        $epgId = $this->guideEpgId($context);
        EpgChannel::where('epg_id', $epgId)->first()->forceFill(['additional_display_names' => ['102']])->saveQuietly();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conflicting channel-number labels');
        (new GuideMapService)->resolve(['guide_epg_id' => $epgId], $context);
    }

    public function test_reused_preparation_observes_edits_without_timestamp_changes_and_new_duplicates(): void
    {
        [$context] = $this->context();
        $epgId = $this->guideEpgId($context);
        $maps = new GuideMapService;
        $payload = ['guide_epg_id' => $epgId];
        $first = $maps->resolve($payload, $context);
        $this->assertSame($first, $maps->resolve($payload, $context));
        DB::table('epg_channels')->where('epg_id', $epgId)->update(['display_name' => '102', 'name' => 'Updated Name']);
        $changed = $maps->resolve($payload, $context);
        $this->assertSame([102 => 'bbc.one'], $changed['map']);
        $this->assertContains('Updated Name', $changed['aliases'][102]);
        $this->assertSame($first['channel_ids'][101], $changed['channel_ids'][102]);

        EpgChannel::factory()->createQuietly([
            'epg_id' => $epgId, 'user_id' => $context->user->id,
            'channel_id' => 'bbc.one', 'display_name' => '102', 'name' => 'Updated Name',
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate records');
        $maps->resolve($payload, $context);
    }

    public function test_reused_preparation_checks_current_guide_ownership(): void
    {
        [$context] = $this->context();
        $epgId = $this->guideEpgId($context);
        $maps = new GuideMapService;
        $maps->resolve(['guide_epg_id' => $epgId], $context);
        $other = User::factory()->create();
        DB::table('epgs')->where('id', $epgId)->update(['user_id' => $other->id]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found or is not available');
        $maps->resolve(['guide_epg_id' => $epgId], $context);
    }
}
