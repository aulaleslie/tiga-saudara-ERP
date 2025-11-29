<?php

namespace App\Livewire\Pos;

use App\Models\PosSession;
use App\Support\PosLocationResolver;
use App\Support\PosSessionManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class SessionManager extends Component
{
    public ?PosSession $session = null;
    public ?float $cashFloat = null;
    public ?float $expectedCash = null;
    public ?float $actualCash = null;
    public string $pausePassword = '';
    public string $resumePassword = '';
    public string $closePassword = '';

    public function mount(PosSessionManager $manager): void
    {
        $this->refreshSession($manager);
    }

    public function updatedCashFloat($value): void
    {
        $this->cashFloat = $this->sanitizeFloat($value);
    }

    public function updatedExpectedCash($value): void
    {
        $this->expectedCash = $this->sanitizeFloat($value, allowNegative: true);
    }

    public function updatedActualCash($value): void
    {
        $this->actualCash = $this->sanitizeFloat($value, allowNegative: true);
    }

    public function startSession(PosSessionManager $manager): void
    {
        $this->validate([
            'cashFloat' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $locationId = PosLocationResolver::resolveId();
            $manager->start($this->cashFloat ?? 0.0, $locationId);
            session()->flash('success', 'Sesi POS dimulai.');
            $this->reset(['cashFloat']);
        } catch (Throwable $throwable) {
            $this->handleException($throwable, 'cashFloat');
            return;
        }

        $this->refreshSession($manager);

        // Redirect to POS page after successful session creation
        $this->redirect(route('app.pos.index'), navigate: true);
    }

    public function pauseSession(PosSessionManager $manager): void
    {
        $this->validate([
            'pausePassword' => ['required', 'string'],
        ]);

        try {
            $manager->pause($this->pausePassword);
            session()->flash('success', 'Sesi POS dijeda.');
        } catch (Throwable $throwable) {
            $this->handleException($throwable, 'pausePassword');
            return;
        }

        $this->pausePassword = '';
        $this->refreshSession($manager);
    }

    public function resumeSession(PosSessionManager $manager): void
    {
        $this->validate([
            'resumePassword' => ['required', 'string'],
        ]);

        try {
            $manager->resume($this->resumePassword);
            session()->flash('success', 'Sesi POS dilanjutkan.');
        } catch (Throwable $throwable) {
            $this->handleException($throwable, 'resumePassword');
            return;
        }

        $this->resumePassword = '';
        $this->refreshSession($manager);
    }

    public function closeSession(PosSessionManager $manager): void
    {
        $this->validate([
            'actualCash' => ['required', 'numeric', 'min:0'],
            'expectedCash' => ['nullable', 'numeric'],
            'closePassword' => ['required', 'string'],
        ]);

        try {
            $manager->close($this->actualCash ?? 0.0, $this->expectedCash, $this->closePassword);
            session()->flash('success', 'Sesi POS ditutup.');
        } catch (Throwable $throwable) {
            $this->handleException($throwable, 'actualCash');
            return;
        }

        $this->closePassword = '';
        $this->refreshSession($manager);
    }

    public function render()
    {
        return view('livewire.pos.session-manager');
    }

    protected function sanitizeFloat($value, bool $allowNegative = false): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $numeric = (float) $value;

        if (! $allowNegative) {
            $numeric = max(0.0, $numeric);
        }

        return round($numeric, 2);
    }

    protected function refreshSession(PosSessionManager $manager): void
    {
        $this->session = $manager->current();

        if ($this->session) {
            $this->expectedCash = $this->session->expected_cash;
        }
    }

    protected function handleException(Throwable $throwable, string $field): void
    {
        if ($throwable instanceof ValidationException) {
            $this->setErrorBag($throwable->validator->getMessageBag());
            return;
        }

        if ($throwable instanceof AuthorizationException) {
            $this->addError($field, $throwable->getMessage());
            return;
        }

        report($throwable);
        $this->addError($field, 'Terjadi kesalahan saat memproses sesi POS.');
    }
}
