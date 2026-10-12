<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Sources;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Epg;
use AppLocalPlugins\GuideLink\Services\GuideMapService;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
