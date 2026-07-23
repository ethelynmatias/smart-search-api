<?php

namespace App\Http\Requests;

use App\DTOs\SmartSearch\AMLData;
use Illuminate\Foundation\Http\FormRequest;

class AMLRequest extends FormRequest
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
            'sex' => ['nullable', 'string', 'in:M,F'],
            'country' => ['required', 'string', 'size:2'],
            'document_types' => ['nullable', 'array'],
            'document_types.*' => ['string', 'in:'.implode(',', AMLData::DOCUMENT_TYPES)],
            'client_ref' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'array'],
            'address.flat' => ['nullable', 'string', 'max:50'],
            'address.building' => ['nullable', 'string', 'max:100'],
            'address.address_1' => ['required', 'string', 'max:255'],
            'address.address_2' => ['nullable', 'string', 'max:255'],
            'address.town' => ['required', 'string', 'max:100'],
            'address.region' => ['nullable', 'string', 'max:100'],
            'address.postcode' => ['required', 'string', 'max:10'],
        ];
    }
}
