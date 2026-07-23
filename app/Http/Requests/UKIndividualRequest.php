<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UKIndividualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:20'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'address' => ['required', 'array'],
            'address.line_1' => ['required', 'string', 'max:255'],
            'address.line_2' => ['nullable', 'string', 'max:255'],
            'address.town' => ['required', 'string', 'max:100'],
            'address.postcode' => ['required', 'string', 'max:10'],
        ];
    }
}
