<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StartProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'recipe' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'idempotencyKey' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'recipe.required' => 'The recipe field is required.',
            'recipe.string' => 'The recipe field must be a string.',
            'recipe.max' => 'The recipe field must not be greater than 64 characters.',
            'recipe.regex' => 'The recipe field may only contain lowercase letters, numbers, and dashes.',
            'idempotencyKey.required' => 'The Idempotency-Key header is required.',
            'idempotencyKey.uuid' => 'The Idempotency-Key header must be a valid UUID.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotencyKey' => $this->header('Idempotency-Key'),
        ]);
    }
}
