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
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('outcome') !== 'request_changes') {
                return;
            }

            $host = strtolower((string) parse_url(
                (string) $this->input('github_feedback_url'),
                PHP_URL_HOST,
            ));

            if (! in_array($host, ['github.com', 'www.github.com'], true)) {
                $validator->errors()->add(
                    'github_feedback_url',
                    'Feedback must be published on GitHub; enter its github.com URL.'
                );
            }
        }];
    }
}
