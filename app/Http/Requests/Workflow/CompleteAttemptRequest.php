<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class CompleteAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', 'max:80'],
        ];
    }
}
