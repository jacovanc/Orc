<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class StartWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'workflow_definition_id' => ['required', 'integer', 'exists:workflow_definitions,id'],
            'github_issue_number' => ['required', 'integer', 'min:1'],
        ];
    }
}
