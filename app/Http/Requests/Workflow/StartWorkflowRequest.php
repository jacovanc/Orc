<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'github_issue_url' => rtrim(trim((string) $this->input('github_issue_url')), '/'),
        ]);
    }

    public function rules(): array
    {
        return [
            'workflow_definition_id' => ['required', 'integer', 'exists:workflow_definitions,id'],
            'github_issue_number' => ['required', 'integer', 'min:1'],
            'github_issue_url' => ['required', 'url:https', 'max:2048'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $url = (string) $this->input('github_issue_url');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = strtolower(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));
            $expected = strtolower(sprintf(
                '/%s/issues/%s',
                $this->route('project')?->github_repository,
                $this->input('github_issue_number'),
            ));

            if (! in_array($host, ['github.com', 'www.github.com'], true) || $path !== $expected) {
                $validator->errors()->add(
                    'github_issue_url',
                    'Enter the matching github.com issue URL for this repository and issue number.'
                );
            }
        }];
    }
}
