<?php

namespace App\Exceptions;

use App\Services\SessionIncidentDiagnosticsService;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register(): void
    {
        $this->renderable(function (\Modules\Purchase\Exceptions\PurchaseSourceOperationNotAllowedException $e, $request) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            toast($e->getMessage(), 'error');
            return redirect()->back();
        });

        $this->renderable(function (HttpException $e, $request) {
            if ($e->getStatusCode() !== 419 || !$e->getPrevious() instanceof TokenMismatchException) {
                return null;
            }

            return $this->renderSessionExpiredIncident($request);
        });
    }

    /**
     * Build the incident-aware 419 response. Diagnostic failures here must
     * never replace or change the underlying 419 status.
     */
    protected function renderSessionExpiredIncident($request)
    {
        $incidentId = 'ERP-INCIDENT-UNAVAILABLE';
        $isTransactionSensitive = false;

        try {
            /** @var SessionIncidentDiagnosticsService $diagnostics */
            $diagnostics = app(SessionIncidentDiagnosticsService::class);

            $incidentId = $diagnostics->generateIncidentId();
            $routeName = optional($request->route())->getName();
            $isTransactionSensitive = $diagnostics->isTransactionSensitiveRoute($routeName);

            $diagnostics->record('csrf_mismatch', array_merge(
                ['incident_id' => $incidentId],
                $diagnostics->requestContext($request),
                $diagnostics->clientPageContext($request),
                $diagnostics->csrfDiagnosticContext($request),
                ['session_fingerprint' => $request->hasSession()
                    ? $diagnostics->fingerprint($request->session()->getId())
                    : null,
                ],
            ), 'warning');
        } catch (\Throwable $e) {
            // Diagnostic generation must never block the 419 response itself.
        }

        $isLivewireOrAjax = $request->hasHeader('X-Livewire') || $request->ajax() || $request->expectsJson();

        $payload = [
            'incident_id' => $incidentId,
            'message' => __('Sesi Anda telah berakhir. Silakan muat ulang halaman atau masuk kembali.'),
            'transaction_sensitive' => $isTransactionSensitive,
            // Only offer a "reload this page" link for GET/HEAD requests; a POST/PUT/PATCH/DELETE
            // failure means reloading the same URL as a GET could hit a route that doesn't support it.
            'is_safe_reload' => $request->isMethod('GET') || $request->isMethod('HEAD'),
        ];

        if ($isLivewireOrAjax) {
            $response = response()->json($payload, 419);
        } else {
            $response = response()->view('errors.419', $payload, 419);
        }

        $response->headers->set('X-ERP-Incident-ID', $incidentId);
        $response->setCache(['no_store' => true]);

        return $response;
    }
}
