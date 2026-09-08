<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class HumanActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', 'max:80'],
            'github_feedback_url' => ['nullable', 'required_if:outcome,request_changes', 'url:https', 'max:2048'],
            'github_feedback_confirmed' => ['nullable', 'required_if:outcome,request_changes', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('outcome') !== 'request_changes') {
                return;
            }

            $url = (string) $this->input('github_feedback_url');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $run = $this->route('workflowRun');
            $publication = $run?->stageRuns()
                ->whereNotNull('github_pull_request_number')
                ->latest('attempt_number')
                ->first();
            $path = strtolower(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));
            $expected = $publication
                ? strtolower('/'.$run->github_repository.'/pull/'.$publication->github_pull_request_number)
                : null;

            if (
                ! in_array($host, ['github.com', 'www.github.com'], true)
                || ! $expected
                || ($path !== $expected && ! str_starts_with($path, $expected.'/'))
            ) {
                $validator->errors()->add(
                    'github_feedback_url',
                    'Enter a feedback or review URL from the pull request bound to this workflow.'
                );
            }
        }];
    }
}
