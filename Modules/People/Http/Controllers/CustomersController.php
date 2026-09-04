<?php

namespace Modules\People\Http\Controllers;

use App\Services\IdempotencyService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Modules\People\DataTables\CustomersDataTable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\People\Rules\UniqueCustomerField;
use Modules\Purchase\Entities\PaymentTerm;

class CustomersController extends Controller
{

    public function __construct()
    {
        $this->middleware('idempotency')->only('store');
    }

    public function index(CustomersDataTable $dataTable)
    {
        abort_if(Gate::denies('customers.access'), 403);

        return $dataTable->render('people::customers.index');
    }


    public function create(Request $request): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('customers.create'), 403);

        $paymentTerms = PaymentTerm::all(); // Ambil semua PaymentTerm
        $idempotencyToken = IdempotencyService::tokenFromRequest($request);

        return view('people::customers.create', compact('paymentTerms', 'idempotencyToken'));
    }



    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('customers.create'), 403);

        // Validate the request data
        $settingId = session('setting_id');
        $request->validate([
            'customer_name' => [
                'required',
                'string',
                'max:255',
                (new UniqueCustomerField('customer_name'))->setMessage('Nama pelanggan sudah digunakan.'),
            ],
            'contact_name' => [
                'nullable',
                'string',
                'max:255',
                (new UniqueCustomerField('contact_name'))->setMessage('Nama kontak sudah digunakan.'),
            ],
            'customer_phone' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($settingId) {
                    if (!empty($value)) {
                        $exists = DB::table('customers')
                            ->where('setting_id', $settingId)
                            ->where('customer_phone', $value)
                            ->exists();
                        if ($exists) {
                            $fail('Nomor telepon sudah digunakan.');
                        }
                    }
                }
            ],
            'payment_term_id' => 'nullable|exists:payment_terms,id', // Validasi PaymentTerm

            // Bank fields validation, mandatory only if one is filled
            'bank_name' => 'nullable|required_with:bank_branch,account_number,account_holder|string|max:255',
            'bank_branch' => 'nullable|required_with:bank_name,account_number,account_holder|string|max:255',
            'account_number' => 'nullable|required_with:bank_name,bank_branch,account_holder|string|max:255',
            'account_holder' => 'nullable|required_with:bank_name,bank_branch,account_number|string|max:255',

            // customer_name already validated above
            'customer_email' => [
                'nullable',
                'email',
                'max:255',
                function ($attribute, $value, $fail) use ($settingId) {
                    if (!empty($value)) {
                        $exists = DB::table('customers')
                            ->where('setting_id', $settingId)
                            ->where('customer_email', $value)
                            ->exists();
                        if ($exists) {
                            $fail('Email sudah digunakan.');
                        }
                    }
                }
            ],
            'identity' => 'nullable|string|max:50',
            'identity_number' => [
                'nullable',
                'required_if:identity,KTP,SIM,Passport',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($settingId) {
                    if (!empty($value)) {
                        $exists = DB::table('customers')
                            ->where('setting_id', $settingId)
                            ->where('identity_number', $value)
                            ->exists();
                        if ($exists) {
                            $fail('Nomor identitas sudah digunakan.');
                        }
                    }
                }
            ],
            'npwp' => [
                'nullable',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($settingId) {
                    if (!empty($value)) {
                        $exists = DB::table('customers')
                            ->where('setting_id', $settingId)
                            ->where('npwp', $value)
                            ->exists();
                        if ($exists) {
                            $fail('NPWP sudah digunakan.');
                        }
                    }
                }
            ],
            'billing_address' => 'nullable|string|max:500',
            'shipping_address' => 'nullable|string|max:500',
            'additional_info' => 'nullable|string|max:1000',
            'tier' => 'nullable|in:WHOLESALER,RESELLER',
        ], [
            'customer_name.required' => 'Nama pelanggan / perusahaan wajib diisi.',
            'customer_phone.required' => 'Nomor telepon wajib diisi.',

            'bank_name.required_with' => 'Nama bank wajib diisi jika salah satu informasi bank diisi.',
            'bank_branch.required_with' => 'Cabang bank wajib diisi jika salah satu informasi bank diisi.',
            'account_number.required_with' => 'Nomor rekening wajib diisi jika salah satu informasi bank diisi.',
            'account_holder.required_with' => 'Pemegang akun wajib diisi jika salah satu informasi bank diisi.',

            'identity_number.required_if' => 'Nomor identitas wajib diisi jika identitas dipilih.',
        ]);

        // Assign the setting_id from the session
        $settingId = session('setting_id');

        try {
            \Illuminate\Support\Facades\Log::debug('Attempting to create customer', [
                'payload' => $request->all(),
                'setting_id' => $settingId
            ]);

            // Create the customer
            Customer::create([
                'setting_id' => $settingId,
                'payment_term_id' => $request->payment_term_id, // Menyimpan payment_term_id
                'contact_name' => $this->nullableInput($request->contact_name),
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_email' => $this->nullableInput($request->customer_email),
                'identity' => $request->identity,
                'identity_number' => $this->nullableInput($request->identity_number),
                'npwp' => $this->nullableInput($request->npwp),
                'billing_address' => $request->billing_address,
                'shipping_address' => $request->shipping_address,
                'city' => $request->city ?? '',
                'country' => $request->country ?? '',
                'address' => $request->address ?? '',
                'additional_info' => $request->additional_info ?? '',

                // Optional Bank information
                'bank_name' => $request->bank_name,
                'bank_branch' => $request->bank_branch,
                'account_number' => $request->account_number,
                'account_holder' => $request->account_holder,
                'tier' => $request->tier,
            ]);

            toast('Pelanggan Ditambahkan!', 'success');

            return redirect()->route('customers.index');
        } catch (QueryException $e) {
            \Illuminate\Support\Facades\Log::error('Customer creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
                'setting_id' => $settingId
            ]);

            if ($this->isIntegrityConstraintViolation($e)) {
                return redirect()
                    ->back()
                    ->withErrors($this->duplicateConstraintErrors($e))
                    ->withInput();
            }

            toast('Gagal menyimpan pelanggan. Silakan hubungi administrator.', 'error');

            return redirect()->back()->withInput();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Customer creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
                'setting_id' => $settingId
            ]);

            toast('Gagal menyimpan pelanggan. Silakan hubungi administrator.', 'error');
            return redirect()->back()->withInput();
        }
    }


    public function show(Customer $customer): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('customers.show'), 403);
        $customer->load('paymentTerm');
        return view('people::customers.show', compact('customer'));
    }


    public function edit(Customer $customer): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('customers.edit'), 403);

        $paymentTerms = PaymentTerm::all(); // Ambil semua PaymentTerm
        return view('people::customers.edit', compact('customer', 'paymentTerms'));
    }


    public function update(Request $request, Customer $customer): RedirectResponse
    {
        abort_if(Gate::denies('customers.edit'), 403);

        $settingId = session('setting_id');
        $request->validate([
            'customer_name' => [
                'required',
                'string',
                'max:255',
                (new UniqueCustomerField('customer_name', $customer->id))->setMessage('Nama pelanggan sudah digunakan.'),
            ],
            'contact_name' => [
                'nullable',
                'string',
                'max:255',
                (new UniqueCustomerField('contact_name', $customer->id))->setMessage('Nama kontak sudah digunakan.'),
            ],
            'customer_phone' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($settingId, $customer) {
                    if (!empty($value)) {
                        $exists = DB::table('customers')
                            ->where('setting_id', $settingId)
                            ->where('customer_phone', $value)
                            ->where('id', '!=', $customer->id)
                            ->exists();
                        if ($exists) {
                            $fail('Nomor telepon sudah digunakan.');
                        }
                    }
                }
            ],
            'payment_term_id' => 'nullable|exists:payment_terms,id', // Validasi PaymentTerm
            'customer_email' => [
                'nullable',
                'email',
                'max:255',
                function ($attribute, $value, $fail) use ($settingId, $customer) {
                    if (!empty($value)) {
                        $exists = DB::table('customers')
                            ->where('setting_id', $settingId)
                            ->where('customer_email', $value)
                            ->where('id', '!=', $customer->id)
                            ->exists();
                        if ($exists) {
                            $fail('Email sudah digunakan.');
                        }
                    }
                }
            ],
            'identity' => 'nullable|string|max:50',
            'identity_number' => [
                'nullable',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($settingId, $customer) {
                    if (!empty($value)) {
                        $exists = DB::table('customers')
                            ->where('setting_id', $settingId)
                            ->where('identity_number', $value)
                            ->where('id', '!=', $customer->id)
                            ->exists();
                        if ($exists) {
                            $fail('Nomor identitas sudah digunakan.');
                        }
                    }
                }
            ],
            'fax' => 'nullable|string|max:100',
            'npwp' => [
                'nullable',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($settingId, $customer) {
                    if (!empty($value)) {
                        $exists = DB::table('customers')
                            ->where('setting_id', $settingId)
                            ->where('npwp', $value)
                            ->where('id', '!=', $customer->id)
                            ->exists();
                        if ($exists) {
                            $fail('NPWP sudah digunakan.');
                        }
                    }
                }
            ],
            'billing_address' => 'nullable|string|max:500',
            'shipping_address' => 'nullable|string|max:500',
            'bank_name' => 'nullable|string|max:255',
            'bank_branch' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'account_holder' => 'nullable|string|max:255',
            'tier' => 'nullable|in:WHOLESALER,RESELLER',
        ]);

        try {
            \Illuminate\Support\Facades\Log::debug('Attempting to update customer', [
                'customer_id' => $customer->id,
                'payload' => $request->all(),
                'setting_id' => $settingId
            ]);

            $customer->update([
                'customer_name' => $request->customer_name,
                'contact_name' => $this->nullableInput($request->contact_name),
                'customer_phone' => $request->customer_phone,
                'payment_term_id' => $request->payment_term_id, // Menyimpan payment_term_id
                'customer_email' => $request->customer_email ?? '',
                'identity' => $request->identity,
                'identity_number' => $request->identity_number,
                'fax' => $request->fax,
                'npwp' => $request->npwp,
                'billing_address' => $request->billing_address,
                'shipping_address' => $request->shipping_address,
                'bank_name' => $request->bank_name,
                'bank_branch' => $request->bank_branch,
                'account_number' => $request->account_number,
                'account_holder' => $request->account_holder,
                'additional_info' => $request->additional_info,
                'tier' => $request->tier,
            ]);

            toast('Data Pelanggan Diperbaharui!', 'info');

            return redirect()->route('customers.index');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Customer update failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
                'setting_id' => $settingId
            ]);

            toast('Gagal memperbaharui pelanggan. Silakan hubungi administrator.', 'error');
            return redirect()->back()->withInput();
        }
    }


    public function toggleStatus(Customer $customer, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(! Gate::allows('customers.edit') && ! Gate::allows('customers.delete'), 403);

        try {
            if ($customer->is_active) {
                $lifecycleService->deactivate($customer);
                toast('Pelanggan berhasil dinonaktifkan!', 'info');
            } else {
                $lifecycleService->reactivate($customer);
                toast('Pelanggan berhasil diaktifkan kembali!', 'success');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->back();
    }

    public function destroy(Customer $customer, \App\Services\MasterDataLifecycleService $lifecycleService): RedirectResponse
    {
        abort_if(! Gate::allows('customers.edit') && ! Gate::allows('customers.delete'), 403);

        try {
            $lifecycleService->deactivate($customer);
            toast('Pelanggan berhasil dinonaktifkan!', 'info');
        } catch (\Illuminate\Validation\ValidationException $e) {
            toast($e->getMessage(), 'error');
        }

        return redirect()->route('customers.index');
    }

    private function nullableInput(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    private function isIntegrityConstraintViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            || str_contains($exception->getMessage(), 'SQLSTATE[23000]');
    }

    private function duplicateConstraintErrors(QueryException $exception): array
    {
        return match ($this->extractConstraintName($exception)) {
            'customers_setting_email_unique' => ['customer_email' => 'Email sudah digunakan.'],
            'customers_setting_phone_unique' => ['customer_phone' => 'Nomor telepon sudah digunakan.'],
            'customers_setting_identity_unique' => ['identity_number' => 'Nomor identitas sudah digunakan.'],
            'customers_setting_npwp_unique' => ['npwp' => 'NPWP sudah digunakan.'],
            default => ['contact_name' => 'Data pelanggan duplikat terdeteksi. Periksa kembali data yang diinput.'],
        };
    }

    private function extractConstraintName(QueryException $exception): ?string
    {
        $message = $exception->getMessage();

        if (preg_match("/for key '([^']+)'/", $message, $matches) === 1) {
            return $matches[1];
        }

        if (str_contains($message, 'customers.setting_id, customers.customer_email')) {
            return 'customers_setting_email_unique';
        }

        if (str_contains($message, 'customers.setting_id, customers.customer_phone')) {
            return 'customers_setting_phone_unique';
        }

        if (str_contains($message, 'customers.setting_id, customers.identity_number')) {
            return 'customers_setting_identity_unique';
        }

        if (str_contains($message, 'customers.setting_id, customers.npwp')) {
            return 'customers_setting_npwp_unique';
        }

        return null;
    }
}
