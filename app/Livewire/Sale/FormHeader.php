<?php

namespace App\Livewire\Sale;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Modules\Purchase\Entities\PaymentTerm;

class FormHeader extends Component
{
    public $reference = 'SL';
    public $customerId;
    public $date;
    public $dueDate;
    public $paymentTermId;
    public $paymentTerms;

    // Listen for an event emitted by your customer auto-complete component.
    protected $listeners = ['customerSelected' => 'handleCustomerSelected'];

    public function mount()
    {
        // Initialize with today's date.
        $this->date = Carbon::now()->format('Y-m-d');
        $this->dueDate = $this->date;

        // Load available payment terms.
        $this->paymentTerms = PaymentTerm::all();
    }

    // When the date or payment term is updated, recalc the due date.
    public function updatedDate($value)
    {
        $this->calculateDueDate();
    }

    public function updatedPaymentTermId($value)
    {
        $this->calculateDueDate();
    }

    // This method will be triggered when a customer is selected
    public function handleCustomerSelected($customer)
    {
        Log::info('customer selected: ', [
            'customer' =>  $customer,
        ]);

        // If the customer has a default payment term, use it.
        if ($customer && $customer['payment_term_id']) {
            $this->paymentTermId = $customer['payment_term_id'];
            $this->customerId = $customer['id'];
        } else {
            $this->paymentTermId = null;
            $this->customerId = null;
        }
        $this->calculateDueDate();
    }

    protected function calculateDueDate()
    {
        if ($this->paymentTermId) {
            // Find the selected payment term from the loaded list.
            $term = $this->paymentTerms->firstWhere('id', $this->paymentTermId);
            if ($term && $term->longevity) {
                // Add the longevity (in days) to the selected date.
                $this->dueDate = Carbon::parse($this->date)
                    ->addDays($term->longevity)
                    ->format('Y-m-d');
                return;
            }
        }
        // If no payment term is selected or longevity is not set, due date equals date.
        $this->dueDate = $this->date;
    }

    public function render()
    {
        return view('livewire.sale.form-header');
    }
}
