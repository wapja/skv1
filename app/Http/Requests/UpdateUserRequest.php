<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:50',
            'last_name' => 'required|string|max:255',
            'internal_id' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'email' => 'required|email|max:255',
            'status' => ['required', Rule::in(['active', 'pending_activation', 'disabled'])],
            'locale' => ['required', Rule::in(['nl', 'en'])],
        ];
    }
}
