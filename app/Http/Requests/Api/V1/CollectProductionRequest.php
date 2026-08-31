<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CollectProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'idempotencyKey' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
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
