<?php

namespace App\Http\Requests\Finance;

use App\Actions\Finance\Import\CsvReader;
use App\Actions\Finance\Import\ImportUpload;
use App\Models\Finance\AccountType;
use App\Models\Finance\AmountMode;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\TransactionStatus;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The mapper wizard's settings. Column fields are 0-based positions into the file's header, which
 * comes from the uploaded file (or the saved mapping when editing without one), never the client.
 */
class ImportMappingRequest extends FormRequest
{
    /**
     * @var list<string>|null
     */
    private ?array $resolvedHeader = null;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $lastColumn = max(count($this->fileHeader()) - 1, 0);
        $column = ['nullable', 'integer', 'min:0', "max:{$lastColumn}"];
        $importable = AccountType::importable();
        $isPreview = $this->routeIs('finance.import.preview');
        $shape = [
            'encoding' => ['required', Rule::in(CsvReader::ENCODINGS)],
            'delimiter' => ['required', Rule::in(CsvReader::DELIMITERS)],
            'skip_lines' => ['required', 'integer', 'min:0', 'max:50'],
        ];

        // Previewing only the file's shape (header and raw rows) before any column is chosen.
        if ($isPreview && ! $this->boolean('parse')) {
            return $shape;
        }

        return [
            ...$shape,
            'name' => [$isPreview ? 'nullable' : 'required', 'string', 'max:255'],
            'account_id' => [
                'nullable',
                $isPreview ? 'nullable' : 'required_without:new_account_name',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('archived_at')
                    ->whereIn('type', $importable),
            ],
            'new_account_name' => ['nullable', 'string', 'max:255'],
            'new_account_type' => ['nullable', 'required_with:new_account_name', Rule::in($importable)],
            'date_column' => ['required', ...array_slice($column, 1)],
            'date_format' => ['required', 'string', 'max:50'],
            'description_column' => ['required', ...array_slice($column, 1)],
            'amount_mode' => ['required', Rule::enum(AmountMode::class)],
            'amount_column' => ['required_if:amount_mode,signed', ...$column],
            'debit_column' => ['required_if:amount_mode,split', ...$column],
            'credit_column' => ['required_if:amount_mode,split', ...$column],
            'invert_sign' => ['boolean'],
            'decimal_separator' => ['required', Rule::in(['.', ','])],
            'thousands_separator' => ['nullable', Rule::in([',', '.', "'", ' ']), 'different:decimal_separator'],
            'fee_column' => $column,
            'balance_column' => $column,
            'currency_column' => $column,
            'status_column' => $column,
            'status_map' => ['nullable', 'required_with:status_column', 'array'],
            'status_map.*' => [Rule::enum(TransactionStatus::class)],
            'filter_column' => $column,
            'filter_value' => ['nullable', 'required_with:filter_column', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (count($this->fileHeader()) < 2) {
                    $validator->errors()->add('delimiter', 'With these settings the file has no header row of at least two columns.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.required_without' => 'Choose an account or name a new one.',
            'account_id.exists' => 'Choose one of your active checking, savings or credit card accounts.',
            'amount_column.required_if' => 'Choose the amount column.',
            'debit_column.required_if' => 'Choose the debit column.',
            'credit_column.required_if' => 'Choose the credit column.',
            'status_map.required_with' => 'Map the status values.',
            'filter_value.required_with' => 'Enter the value the filter column must equal.',
            'thousands_separator.different' => 'The thousands separator must differ from the decimal separator.',
        ];
    }

    /**
     * The header of the file being mapped, read with the submitted settings.
     *
     * @return list<string>
     */
    public function fileHeader(): array
    {
        if ($this->resolvedHeader !== null) {
            return $this->resolvedHeader;
        }

        $mapping = $this->route('mapping');

        if ($mapping instanceof ImportMapping) {
            return $this->resolvedHeader = $mapping->header;
        }

        /** @var User $user */
        $user = $this->user();
        $upload = ImportUpload::findOrFail($user, (string) $this->route('upload'));

        return $this->resolvedHeader = in_array($this->input('encoding'), CsvReader::ENCODINGS, true)
            && in_array($this->input('delimiter'), CsvReader::DELIMITERS, true)
            ? CsvReader::read($upload->bytes(), (string) $this->input('encoding'), (string) $this->input('delimiter'), max(0, $this->integer('skip_lines')))['header']
            : [];
    }

    /**
     * The mapping attributes, without the account choice.
     *
     * @return array<string, mixed>
     */
    public function mappingAttributes(): array
    {
        $attributes = $this->safe()->except(['account_id', 'new_account_name', 'new_account_type', 'parse']);
        $attributes['invert_sign'] = $this->boolean('invert_sign');
        $attributes['header'] = $this->fileHeader();

        if ($attributes['amount_mode'] === AmountMode::Signed->value) {
            $attributes['debit_column'] = $attributes['credit_column'] = null;
        } else {
            $attributes['amount_column'] = null;
        }

        if (($attributes['status_column'] ?? null) === null) {
            $attributes['status_map'] = null;
        }

        if (($attributes['filter_column'] ?? null) === null) {
            $attributes['filter_value'] = null;
        }

        return $attributes;
    }
}
