<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExportRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'format' => ['nullable', 'in:xlsx'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sheets' => ['required', 'array', 'min:1'],
            'sheets.*.name' => ['required', 'string'],
            'sheets.*.columns' => ['nullable', 'array'],
            'sheets.*.filters' => ['nullable', 'array'],
            'sheets.*.sort' => ['nullable', 'array'],
            'sheets.*.group_by' => ['nullable', 'array'],
            'sheets.*.metrics' => ['nullable', 'array'],
        ];
    }
}
