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
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'status' => ['required', Rule::in(['active', 'pending_activation', 'disabled'])],
            'locale' => ['required', Rule::in(['nl', 'en'])],
        ];
    }
}
