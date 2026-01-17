<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class WarnUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can warn users
        return $this->user() && $this->user()->hasRole('admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'max:500',
                'min:10'
            ],
            'message' => [
                'required',
                'string',
                'max:1000',
                'min:20'
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan warning wajib diisi.',
            'reason.min' => 'Alasan warning minimal 10 karakter.',
            'reason.max' => 'Alasan warning maksimal 500 karakter.',
            'message.required' => 'Pesan warning wajib diisi.',
            'message.min' => 'Pesan warning minimal 20 karakter.',
            'message.max' => 'Pesan warning maksimal 1000 karakter.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'alasan',
            'message' => 'pesan',
        ];
    }
}
