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
        $this->seedProofWorkflow();
        $this->seedRealDevelopmentWorkflow();
        $this->seedIndependentQaWorkflow();
        $this->seedMergeWorkflow();
        $this->seedExplanationWorkflow();
    }

    private function seedProofWorkflow(): void
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

    private function seedRealDevelopmentWorkflow(): void
    {
        $definition = WorkflowDefinition::query()->firstOrCreate(
            ['key' => 'development', 'version' => 2],
            ['name' => 'Development delivery', 'is_active' => true],
        );

        if ($definition->stages()->exists()) {
            if ($definition->stages()->count() !== 5 || $definition->transitions()->count() !== 6) {
                throw new RuntimeException('The immutable development workflow v2 is incomplete.');
            }

            return;
        }

        $development = $definition->stages()->create([
            'key' => 'development',
            'name' => 'Real Development',
            'type' => StageType::Agent,
            'config' => [
                'agent_mode' => 'real_development',
                'role' => 'implementation',
                'outcomes' => ['success', 'blocked'],
            ],
            'position' => 1,
        ]);
        $qaProof = $definition->stages()->create([
            'key' => 'qa_proof',
            'name' => 'QA Integration Proof — not validation',
            'type' => StageType::Agent,
            'config' => [
                'agent_mode' => 'proof_qa',
                'role' => 'integration_proof',
                'outcomes' => ['proof_complete'],
            ],
            'position' => 2,
        ]);
        $blockedReview = $definition->stages()->create([
            'key' => 'development_blocked',
            'name' => 'Development Blocked Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']],
            'position' => 3,
        ]);
        $humanReview = $definition->stages()->create([
            'key' => 'human_review',
            'name' => 'Human Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['request_changes', 'approve']],
            'position' => 4,
        ]);
        $done = $definition->stages()->create([
            'key' => 'done',
            'name' => 'Done',
            'type' => StageType::Terminal,
            'config' => ['outcomes' => []],
            'position' => 5,
        ]);

        $definition->transitions()->createMany([
            ['from_stage_id' => $development->id, 'outcome' => 'success', 'to_stage_id' => $qaProof->id],
            ['from_stage_id' => $development->id, 'outcome' => 'blocked', 'to_stage_id' => $blockedReview->id],
            ['from_stage_id' => $qaProof->id, 'outcome' => 'proof_complete', 'to_stage_id' => $humanReview->id],
            ['from_stage_id' => $blockedReview->id, 'outcome' => 'retry', 'to_stage_id' => $development->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'request_changes', 'to_stage_id' => $development->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'approve', 'to_stage_id' => $done->id],
        ]);
    }

    private function seedIndependentQaWorkflow(): void
    {
        $definition = WorkflowDefinition::query()->firstOrCreate(
            ['key' => 'development', 'version' => 3],
            ['name' => 'Development delivery', 'is_active' => true],
        );

        if ($definition->stages()->exists()) {
            if ($definition->stages()->count() !== 6 || $definition->transitions()->count() !== 9) {
                throw new RuntimeException('The immutable development workflow v3 is incomplete.');
            }

            return;
        }

        $development = $definition->stages()->create([
            'key' => 'development',
            'name' => 'Real Development',
            'type' => StageType::Agent,
            'config' => [
                'agent_mode' => 'real_development',
                'reuse_prior_publication' => true,
                'role' => 'implementation',
                'outcomes' => ['success', 'blocked'],
            ],
            'position' => 1,
        ]);
        $qa = $definition->stages()->create([
            'key' => 'qa',
            'name' => 'Independent QA',
            'type' => StageType::Agent,
            'config' => [
                'agent_mode' => 'real_qa',
                'role' => 'quality_assurance',
                'outcomes' => ['pass', 'fail', 'blocked'],
            ],
            'position' => 2,
        ]);
        $developmentBlocked = $definition->stages()->create([
            'key' => 'development_blocked',
            'name' => 'Development Blocked Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']],
            'position' => 3,
        ]);
        $qaBlocked = $definition->stages()->create([
            'key' => 'qa_blocked',
            'name' => 'QA Blocked Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']],
            'position' => 4,
        ]);
        $humanReview = $definition->stages()->create([
            'key' => 'human_review',
            'name' => 'Human Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['request_changes', 'approve']],
            'position' => 5,
        ]);
        $done = $definition->stages()->create([
            'key' => 'done',
            'name' => 'Done',
            'type' => StageType::Terminal,
            'config' => ['outcomes' => []],
            'position' => 6,
        ]);

        $definition->transitions()->createMany([
            ['from_stage_id' => $development->id, 'outcome' => 'success', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $development->id, 'outcome' => 'blocked', 'to_stage_id' => $developmentBlocked->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'pass', 'to_stage_id' => $humanReview->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'fail', 'to_stage_id' => $development->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'blocked', 'to_stage_id' => $qaBlocked->id],
            ['from_stage_id' => $developmentBlocked->id, 'outcome' => 'retry', 'to_stage_id' => $development->id],
            ['from_stage_id' => $qaBlocked->id, 'outcome' => 'retry', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'request_changes', 'to_stage_id' => $development->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'approve', 'to_stage_id' => $done->id],
        ]);
    }

    private function seedMergeWorkflow(): void
    {
        $definition = WorkflowDefinition::query()->firstOrCreate(
            ['key' => 'development', 'version' => 4],
            ['name' => 'Development delivery', 'is_active' => true],
        );

        if ($definition->stages()->exists()) {
            if ($definition->stages()->count() !== 8 || $definition->transitions()->count() !== 13) {
                throw new RuntimeException('The immutable development workflow v4 is incomplete.');
            }

            return;
        }

        $development = $definition->stages()->create([
            'key' => 'development',
            'name' => 'Real Development',
            'type' => StageType::Agent,
            'config' => [
                'agent_mode' => 'real_development',
                'reuse_prior_publication' => true,
                'role' => 'implementation',
                'outcomes' => ['success', 'blocked'],
            ],
            'position' => 1,
        ]);
        $qa = $definition->stages()->create([
            'key' => 'qa',
            'name' => 'Independent QA',
            'type' => StageType::Agent,
            'config' => [
                'agent_mode' => 'real_qa',
                'role' => 'quality_assurance',
                'outcomes' => ['pass', 'fail', 'blocked'],
            ],
            'position' => 2,
        ]);
        $developmentBlocked = $definition->stages()->create([
            'key' => 'development_blocked',
            'name' => 'Development Blocked Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']],
            'position' => 3,
        ]);
        $qaBlocked = $definition->stages()->create([
            'key' => 'qa_blocked',
            'name' => 'QA Blocked Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']],
            'position' => 4,
        ]);
        $humanReview = $definition->stages()->create([
            'key' => 'human_review',
            'name' => 'Human Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['request_changes', 'approve']],
            'position' => 5,
        ]);
        $merge = $definition->stages()->create([
            'key' => 'merge',
            'name' => 'Merge',
            'type' => StageType::Agent,
            'config' => [
                'agent_mode' => 'real_merge',
                'role' => 'merge',
                'outcomes' => ['merged', 'requires_review', 'blocked'],
            ],
            'position' => 6,
        ]);
        $mergeBlocked = $definition->stages()->create([
            'key' => 'merge_blocked',
            'name' => 'Merge Blocked Review',
            'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']],
            'position' => 7,
        ]);
        $done = $definition->stages()->create([
            'key' => 'done',
            'name' => 'Done',
            'type' => StageType::Terminal,
            'config' => ['outcomes' => []],
            'position' => 8,
        ]);

        $definition->transitions()->createMany([
            ['from_stage_id' => $development->id, 'outcome' => 'success', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $development->id, 'outcome' => 'blocked', 'to_stage_id' => $developmentBlocked->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'pass', 'to_stage_id' => $humanReview->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'fail', 'to_stage_id' => $development->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'blocked', 'to_stage_id' => $qaBlocked->id],
            ['from_stage_id' => $developmentBlocked->id, 'outcome' => 'retry', 'to_stage_id' => $development->id],
            ['from_stage_id' => $qaBlocked->id, 'outcome' => 'retry', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'request_changes', 'to_stage_id' => $development->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'approve', 'to_stage_id' => $merge->id],
            ['from_stage_id' => $merge->id, 'outcome' => 'merged', 'to_stage_id' => $done->id],
            ['from_stage_id' => $merge->id, 'outcome' => 'requires_review', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $merge->id, 'outcome' => 'blocked', 'to_stage_id' => $mergeBlocked->id],
            ['from_stage_id' => $mergeBlocked->id, 'outcome' => 'retry', 'to_stage_id' => $merge->id],
        ]);
    }

    private function seedExplanationWorkflow(): void
    {
        $definition = WorkflowDefinition::query()->firstOrCreate(
            ['key' => 'development', 'version' => 5],
            ['name' => 'Development delivery', 'is_active' => true],
        );

        if ($definition->stages()->exists()) {
            if ($definition->stages()->count() !== 9 || $definition->transitions()->count() !== 16) {
                throw new RuntimeException('The immutable development workflow v5 is incomplete.');
            }

            return;
        }

        $development = $definition->stages()->create([
            'key' => 'development', 'name' => 'Real Development', 'type' => StageType::Agent,
            'config' => ['agent_mode' => 'real_development', 'reuse_prior_publication' => true, 'role' => 'implementation', 'outcomes' => ['success', 'blocked']], 'position' => 1,
        ]);
        $qa = $definition->stages()->create([
            'key' => 'qa', 'name' => 'Independent QA', 'type' => StageType::Agent,
            'config' => ['agent_mode' => 'real_qa', 'role' => 'quality_assurance', 'outcomes' => ['pass', 'fail', 'blocked']], 'position' => 2,
        ]);
        $developmentBlocked = $definition->stages()->create([
            'key' => 'development_blocked', 'name' => 'Development Blocked Review', 'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']], 'position' => 3,
        ]);
        $qaBlocked = $definition->stages()->create([
            'key' => 'qa_blocked', 'name' => 'QA Blocked Review', 'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']], 'position' => 4,
        ]);
        $humanReview = $definition->stages()->create([
            'key' => 'human_review', 'name' => 'Human Review', 'type' => StageType::Human,
            'config' => ['outcomes' => ['request_changes', 'ask_questions', 'approve']], 'position' => 5,
        ]);
        $explanation = $definition->stages()->create([
            'key' => 'explanation', 'name' => 'Explanation', 'type' => StageType::Agent,
            'config' => ['agent_mode' => 'real_explanation', 'role' => 'explanation', 'outcomes' => ['completed', 'blocked']], 'position' => 6,
        ]);
        $merge = $definition->stages()->create([
            'key' => 'merge', 'name' => 'Merge', 'type' => StageType::Agent,
            'config' => ['agent_mode' => 'real_merge', 'role' => 'merge', 'outcomes' => ['merged', 'requires_review', 'blocked']], 'position' => 7,
        ]);
        $mergeBlocked = $definition->stages()->create([
            'key' => 'merge_blocked', 'name' => 'Merge Blocked Review', 'type' => StageType::Human,
            'config' => ['outcomes' => ['retry']], 'position' => 8,
        ]);
        $done = $definition->stages()->create([
            'key' => 'done', 'name' => 'Done', 'type' => StageType::Terminal,
            'config' => ['outcomes' => []], 'position' => 9,
        ]);

        $definition->transitions()->createMany([
            ['from_stage_id' => $development->id, 'outcome' => 'success', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $development->id, 'outcome' => 'blocked', 'to_stage_id' => $developmentBlocked->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'pass', 'to_stage_id' => $humanReview->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'fail', 'to_stage_id' => $development->id],
            ['from_stage_id' => $qa->id, 'outcome' => 'blocked', 'to_stage_id' => $qaBlocked->id],
            ['from_stage_id' => $developmentBlocked->id, 'outcome' => 'retry', 'to_stage_id' => $development->id],
            ['from_stage_id' => $qaBlocked->id, 'outcome' => 'retry', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'request_changes', 'to_stage_id' => $development->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'ask_questions', 'to_stage_id' => $explanation->id],
            ['from_stage_id' => $humanReview->id, 'outcome' => 'approve', 'to_stage_id' => $merge->id],
            ['from_stage_id' => $explanation->id, 'outcome' => 'completed', 'to_stage_id' => $humanReview->id],
            ['from_stage_id' => $explanation->id, 'outcome' => 'blocked', 'to_stage_id' => $humanReview->id],
            ['from_stage_id' => $merge->id, 'outcome' => 'merged', 'to_stage_id' => $done->id],
            ['from_stage_id' => $merge->id, 'outcome' => 'requires_review', 'to_stage_id' => $qa->id],
            ['from_stage_id' => $merge->id, 'outcome' => 'blocked', 'to_stage_id' => $mergeBlocked->id],
            ['from_stage_id' => $mergeBlocked->id, 'outcome' => 'retry', 'to_stage_id' => $merge->id],
        ]);
    }
}
