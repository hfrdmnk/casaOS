<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)],
            'payee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('payees', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
