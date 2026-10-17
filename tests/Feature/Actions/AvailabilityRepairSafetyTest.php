<?php

namespace AppLocalPlugins\GuideLink\Tests\Feature\Actions;

require_once dirname(__DIR__).'/PluginFeatureTestCase.php';

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Plugins\Support\PluginExecutionContext;
use AppLocalPlugins\GuideLink\Services\ChannelQuarantineService;
use AppLocalPlugins\GuideLink\Services\RunResultRepository;
use AppLocalPlugins\GuideLink\Tests\Feature\PluginFeatureTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class AvailabilityRepairSafetyTest extends PluginFeatureTestCase
{
    use RefreshDatabase;

    public function test_cancellation_preserves_partial_results_without_claiming_unwritten_repairs(): void
    {
        [$context, $playlist, $primary, $backup] = $this->repairFixture();
        $second = $primary->replicate(['uuid']);
        $second->uuid = (string) Str::uuid();
        $second->channel = 102;
        $second->saveQuietly();
        ChannelFailover::create([
            'user_id' => $context->user->id, 'channel_id' => $second->id, 'channel_failover_id' => $backup->id,
        ]);
        Event::listen('eloquent.updated: '.Channel::class, function (Channel $channel) use ($context, $primary): void {
            if ($channel->id === $primary->id) {
                $context->run->forceFill(['cancel_requested' => true])->saveQuietly();
            }
        });

        $result = $this->runGuideLinkAction('apply_repair_availability', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($primary->refresh()->enabled);
        $this->assertFalse($second->refresh()->enabled);
        $this->assertSame('1', $this->repairDetails($primary)['repair_applied']);
        $this->assertSame('0', $this->repairDetails($second)['repair_applied']);
        $this->assertSame('cancelled', $result->status);
        $this->assertSame(1, $result->data['repair']['applied']);
    }

    public function test_repair_rechecks_quarantine_after_planning(): void
    {
        [$context, $playlist, $primary] = $this->repairFixture();
        $context = $this->beforeApply($context, function () use ($context, $playlist, $primary): void {
            DB::table(ChannelQuarantineService::TABLE)->insert([
                'extension_plugin_id' => $context->plugin->id, 'playlist_id' => $playlist->id,
                'channel_id' => $primary->id, 'original_can_merge' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $primary->forceFill(['can_merge' => false])->saveQuietly();
        });

        $result = $this->runGuideLinkAction('apply_repair_availability', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertFalse($primary->refresh()->enabled);
        $this->assertFalse((bool) $primary->can_merge);
        $this->assertSame(0, $result->data['repair']['applied']);
        $this->assertSame('0', $this->repairDetails($primary)['repair_applied']);
        $this->assertSame('quarantined', $this->repairDetails($primary)['repair_skip_reason']);
    }

    public function test_repair_rechecks_enabled_failover_coverage_after_planning(): void
    {
        [$context, $playlist, $primary, $backup] = $this->repairFixture();
        $context = $this->beforeApply($context, fn () => $backup->forceFill(['enabled' => false])->saveQuietly());

        $result = $this->runGuideLinkAction('apply_repair_availability', ['playlist_id' => $playlist->id], $context);

        $this->assertTrue($result->success, $result->summary);
        $this->assertFalse($primary->refresh()->enabled);
        $this->assertSame(0, $result->data['repair']['applied']);
        $this->assertSame('no_enabled_failover', $this->repairDetails($primary)['repair_skip_reason']);
    }

    public function test_write_failure_preserves_successful_repairs_and_failure_details(): void
    {
        [$context, $playlist, $primary, $backup] = $this->repairFixture();
        $second = $primary->replicate(['uuid']);
        $second->uuid = (string) Str::uuid();
        $second->channel = 102;
        $second->saveQuietly();
        ChannelFailover::create(['user_id' => $context->user->id, 'channel_id' => $second->id, 'channel_failover_id' => $backup->id]);
        Event::listen('eloquent.updating: '.Channel::class, function (Channel $channel) use ($second): void {
            if ($channel->id === $second->id) {
                throw new \RuntimeException('Synthetic channel write failure');
            }
        });
        $result = $this->runGuideLinkAction('apply_repair_availability', ['playlist_id' => $playlist->id], $context);
        $this->assertFalse($result->success);
        $this->assertSame(1, $result->data['repair']['applied']);
        $this->assertSame(1, $result->data['repair']['failed']);
        $this->assertTrue($primary->refresh()->enabled);
        $this->assertFalse($second->refresh()->enabled);
        $this->assertSame('1', $this->repairDetails($primary)['repair_applied']);
        $this->assertSame('write_failed', $this->repairDetails($second)['repair_skip_reason']);
        $this->assertSame('Synthetic channel write failure', $this->repairDetails($second)['repair_error']);
    }

    private function repairFixture(): array
    {
        Bus::fake();
        [$context, $playlist] = $this->context(applyPluginSchema: true, dryRun: false);
        $this->assignProfile($context, $playlist, ['repair_mode' => 'reenable_master']);
        $attributes = [
            'playlist_id' => $playlist->id, 'user_id' => $context->user->id,
            'is_vod' => false, 'group_internal' => 'Entertainment', 'channel' => 101,
            'name' => 'BBC One', 'title' => 'BBC One',
        ];
        $primary = Channel::factory()->createQuietly([...$attributes, 'enabled' => false]);
        $backup = Channel::factory()->createQuietly([...$attributes, 'enabled' => true]);
        ChannelFailover::create([
            'user_id' => $context->user->id, 'channel_id' => $primary->id, 'channel_failover_id' => $backup->id,
        ]);

        return [$context, $playlist, $primary, $backup];
    }

    private function repairDetails(Channel $channel): array
    {
        return json_decode(DB::table(RunResultRepository::TABLE)
            ->where('result_type', 'repair')->where('channel_id', $channel->id)->value('details'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function beforeApply(PluginExecutionContext $context, \Closure $callback): PluginExecutionContext
    {
        return new class($context, $callback) extends PluginExecutionContext
        {
            public function __construct(PluginExecutionContext $context, private readonly \Closure $callback)
            {
                parent::__construct($context->plugin, $context->run, $context->trigger, $context->dryRun, $context->hook, $context->user, $context->settings);
            }

            public function heartbeat(?string $message = null, ?int $progress = null, array $state = []): void
            {
                parent::heartbeat($message, $progress, $state);
                if ($progress === 70) {
                    ($this->callback)();
                }
            }
        };
    }
}
