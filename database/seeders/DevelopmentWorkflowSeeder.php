<?php

namespace Database\Seeders;

use App\Domain\Workflow\StageType;
use App\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;
use RuntimeException;

class DevelopmentWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $definition = WorkflowDefinition::query()->firstOrCreate(
            ['key' => 'development', 'version' => 1],
            ['name' => 'Development delivery', 'is_active' => true],
        );

        if ($definition->stages()->exists()) {
            if ($definition->stages()->count() !== 4 || $definition->transitions()->count() !== 5) {
                throw new RuntimeException('The immutable development workflow v1 is incomplete.');
            }

            return;
        }

        $development = $definition->stages()->create([
            'key' => 'development',
            'name' => 'Development',
            'type' => StageType::Agent,
            'config' => ['role' => 'implementation', 'outcomes' => ['success']],
            'position' => 1,
        ]);
        $qa = $definition->stages()->create([
            'key' => 'qa',
            'name' => 'QA',
            'type' => StageType::Agent,
            'config' => ['role' => 'quality_assurance', 'outcomes' => ['fail', 'pass']],
            'position' => 2,
        ]);
        $review = $definition->stages()->create([
            'key' => 'human_review',
            'name' => 'Human Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['request_changes', 'approve']],
            'position' => 3,
        ]);
        $done = $definition->stages()->create([
            'key' => 'done',
            'name' => 'Done',
            'type' => StageType::Terminal,
            'config' => ['outcomes' => []],
            'position' => 4,
        ]);

        $definition->transitions()->createMany([
            ['from_stage_id' => $development->id, 'outcome' => 'success', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'fail', 'to_stage_id' => $development->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'pass', 'to_stage_id' => $review->id],
            ['from_stage_id' => $review->id, 'outcome' => 'request_changes', 'to_stage_id' => $development->id],
            ['from_stage_id' => $review->id, 'outcome' => 'approve', 'to_stage_id' => $done->id],
        ]);
    }
}
