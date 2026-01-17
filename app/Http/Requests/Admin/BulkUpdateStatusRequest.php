<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admins can bulk update status
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
            'user_ids' => [
                'required',
                'array',
                'min:1',
                'max:100' // Limit bulk operations
            ],
            'user_ids.*' => [
                'required',
                'integer',
                'exists:users,id'
            ],
            'status' => [
                'required',
                Rule::in(['active', 'declining', 'watchlist', 'suspended', 'inactive'])
            ],
            'reason' => [
                'required',
                'string',
                'max:500',
                'min:10'
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
            'user_ids.required' => 'Pilih minimal 1 user.',
            'user_ids.min' => 'Pilih minimal 1 user.',
            'user_ids.max' => 'Maksimal 100 user per operasi.',
            'user_ids.*.exists' => 'User tidak ditemukan.',
            'status.required' => 'Status wajib dipilih.',
            'status.in' => 'Status tidak valid.',
            'reason.required' => 'Alasan perubahan status wajib diisi.',
            'reason.min' => 'Alasan minimal 10 karakter.',
            'reason.max' => 'Alasan maksimal 500 karakter.',
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
            'user_ids' => 'daftar user',
            'status' => 'status',
            'reason' => 'alasan',
        ];
    }
}
