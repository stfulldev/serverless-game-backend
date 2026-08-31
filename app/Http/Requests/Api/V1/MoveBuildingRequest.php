<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class MoveBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'x' => ['required', 'integer', 'between:0,999'],
            'y' => ['required', 'integer', 'between:0,999'],
            'idempotencyKey' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'x.required' => 'The x field is required.',
            'x.integer' => 'The x field must be an integer.',
            'x.between' => 'The x field must be between 0 and 999.',
            'y.required' => 'The y field is required.',
            'y.integer' => 'The y field must be an integer.',
            'y.between' => 'The y field must be between 0 and 999.',
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
