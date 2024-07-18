<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute diterima.',
    'active_url' => ':attribute merupakan URL yang tidak sesuai.',
    'after' => ':attribute harus setelah tanggal :date.',
    'after_or_equal' => ':attribute seharusnya sebelum atau sama pada tanggal :date.',
    'alpha' => ':attribute must only contain letters.',
    'alpha_dash' => ':attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => ':attribute hanya boleh berisi huruf dan angka.',
    'array' => ':attribute harus berupa array.',
    'before' => ':attribute seharusnya sebelum tanggal :date.',
    'before_or_equal' => ':attribute seharusnya sebelum atau sama pada tanggal :date.',
    'between' => [
        'numeric' => ':attribute harus diantara :min dan :max.',
        'file' => ':attribute harus diantara :min dan :max kilobytes.',
        'string' => ':attribute harus diantara :min dan :max karakter.',
        'array' => ':attribute harus diantara :min dan :max items.',
    ],
    'boolean' => 'isian :attribute harus benar atau tidak.',
    'confirmed' => ':attribute konfirmasi tidak cocok.',
    'current_password' => 'Kata Sandi Salah.',
    'date' => ':attribute tanggal tidak sesuai.',
    'date_equals' => ':attribute harus sama dengan tanggal :date.',
    'date_format' => ':attribute tidak sesuai dengan format :format.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus :digits digits.',
    'digits_between' => ':attribute harus berada diantara :min dan :max digits.',
    'dimensions' => ':attribute ukuran image sesuai.',
    'distinct' => 'isian :attribute sama.',
    'email' => ':attribute harus alamat email.',
    'ends_with' => ':attribute harus diakhiri dengan following: :values.',
    'exists' => 'data yang dipilih :attribute sesuai.',
    'file' => ':attribute harus berupa sebuah file.',
    'filled' => 'Isian :attribute harus terisi.',
    'gt' => [
        'numeric' => ':attribute harus lebih besar dari :value.',
        'file' => ':attribute harus lebih besar dari :value kilobytes.',
        'string' => ':attribute harus lebih besar dari :value karakter.',
        'array' => ':attribute harus memiliki lebih dari :value items.',
    ],
    'gte' => [
        'numeric' => ':attribute harus lebih besar dari atau sama dengan :value.',
        'file' => ':attribute harus lebih besar dari atau sama dengan :value kilobytes.',
        'string' => ':attribute harus lebih besar dari atau sama denganl :value characters.',
        'array' => ':attribute harus memiliki :value items atau lebih.',
    ],
    'image' => ':attribute harus berupa image.',
    'in' => 'Data yang dipilih :attribute sesuai.',
    'in_array' => 'isian :attribute tidak ditemukan pada :other.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'ip' => ':attribute harus IP address yang sesuai.',
    'ipv4' => 'The :attribute harus alamat IPv4 yang sesuai.',
    'ipv6' => 'The :attribute harus alamat IPv6 yang sesuai.',
    'json' => 'The :attribute harus JSON string yang sesuai.',
    'lt' => [
        'numeric' => ':attribute harus kurang dari :value.',
        'file' => ':attribute harus kurang dari :value kilobytes.',
        'string' => ':attribute harus kurang dari :value karakter.',
        'array' => ':attribute harus kurang dari :value items.',
    ],
    'lte' => [
        'numeric' => ':attribute harus kurang dari atau sama dengan :value.',
        'file' => ':attribute harus kurang dari atau sama dengan :value kilobytes.',
        'string' => ':attribute harus kurang dari atau sama dengan :value karakter.',
        'array' => ':attribute tidak boleh lebih dari :value items.',
    ],
    'max' => [
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'file' => ':attribute tidak boleh lebih dari :max kilobytes.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
        'array' => ':attribute tidak boleh lebih dari :max items.',
    ],
    'mimes' => ':attribute harus berupa file dengan tipe: :values.',
    'mimetypes' => ':attribute harus berupa file dengan tipe: :values.',
    'min' => [
        'numeric' => ':attribute minimal harus :min.',
        'file' => ':attribute minimal harus :min kilobytes.',
        'string' => ':attribute minimal harus :min karakter.',
        'array' => ':attribute minimal harus :min items.',
    ],
    'multiple_of' => 'The :attribute must be a multiple of :value.',
    'not_in' => 'Data yang dipilih :attribute tidak Valid.',
    'not_regex' => ':attribute tidak sesuai dengan format.',
    'numeric' => ':attribute harus berupa angka.',
    'password' => 'Kata Sandi Salah.',
    'present' => 'The :attribute field must be present.',
    'regex' => ':attribute tidak sesuai format.',
    'required' => 'isian :attribute diperlukan.',
    'required_if' => 'isian :attribute diperlukan ketika :other adalah :value.',
    'required_unless' => 'isian :attribute diperlukan :other ada pada :values.',
    'required_with' => 'isian :attribute diperlukan ketika :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'isian :attribute field is required when none of :values are present.',
    'prohibited' => 'isian :attribute tidak dizinkan.',
    'prohibited_if' => 'isian :attribute tidak diizinkan ketika :other adalah :value.',
    'prohibited_unless' => 'isian :attribute tidak diizinkan kecuali :other ada pada :values.',
    'same' => ':attribute dan :other harus sama.',
    'size' => [
        'numeric' => ':attribute harus :size.',
        'file' => ':attribute harus :size kilobytes.',
        'string' => ':attribute harus :size karakter.',
        'array' => ':attribute harus berisi :size items.',
    ],
    'starts_with' => ':attribute harus dimulai dengan salah satu following: :values.',
    'string' => ':attribute harus berupa string.',
    'timezone' => ':attribute harus berupa Zona Waktu.',
    'unique' => ':attribute sudah ada.',
    'uploaded' => ':attribute gagal mengunggah.',
    'url' => ':attribute harus berupa URL yang sesuai.',
    'uuid' => ':attribute harus berupa UUID yang sesuai.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];
