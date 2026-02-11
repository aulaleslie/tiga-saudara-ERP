<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
        $this->renderable(function (AuthenticationException $exception, Request $request) {
            if (! $this->isPosApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'POS_UNAUTHENTICATED',
                'message' => 'Autentikasi diperlukan untuk mengakses POS.',
                'details' => null,
                'trace_id' => $this->traceId($request),
            ], 401);
        });

        $this->renderable(function (AuthorizationException $exception, Request $request) {
            if (! $this->isPosApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'POS_PERMISSION_DENIED',
                'message' => $exception->getMessage() ?: 'Akses POS ditolak.',
                'details' => null,
                'trace_id' => $this->traceId($request),
            ], 403);
        });

        $this->renderable(function (ModelNotFoundException $exception, Request $request) {
            if (! $this->isPosApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'POS_DRAFT_NOT_FOUND',
                'message' => 'Data POS tidak ditemukan.',
                'details' => null,
                'trace_id' => $this->traceId($request),
            ], 404);
        });

        $this->renderable(function (PosException $exception, Request $request) {
            if (! $this->isPosApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'details' => $exception->details(),
                'trace_id' => $this->traceId($request),
            ], $exception->status());
        });

        $this->renderable(function (ValidationException $exception, Request $request) {
            if (! $this->isPosApiRequest($request)) {
                return null;
            }

            return response()->json([
                'code' => 'POS_DRAFT_STATE_INVALID',
                'message' => 'Validasi permintaan POS gagal.',
                'details' => [
                    'errors' => $exception->errors(),
                ],
                'trace_id' => $this->traceId($request),
            ], 422);
        });

        $this->renderable(function (Throwable $exception, Request $request) {
            if (! $this->isPosApiRequest($request)) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = max(400, (int) $exception->getStatusCode());
                $code = 'POS_REQUEST_FAILED';
                $message = $exception->getMessage() ?: 'Permintaan POS gagal diproses.';

                if ($status === 403) {
                    $code = 'POS_PERMISSION_DENIED';
                    $message = $exception->getMessage() ?: 'Akses POS ditolak.';
                } elseif ($status === 401) {
                    $code = 'POS_UNAUTHENTICATED';
                    $message = $exception->getMessage() ?: 'Autentikasi diperlukan untuk mengakses POS.';
                } elseif ($status === 404) {
                    $code = 'POS_DRAFT_NOT_FOUND';
                    $message = $exception->getMessage() ?: 'Data POS tidak ditemukan.';
                }

                return response()->json([
                    'code' => $code,
                    'message' => $message,
                    'details' => null,
                    'trace_id' => $this->traceId($request),
                ], $status);
            }

            report($exception);

            return response()->json([
                'code' => 'POS_REFERENCE_GENERATION_FAILED',
                'message' => 'Terjadi kesalahan internal pada POS.',
                'details' => null,
                'trace_id' => $this->traceId($request),
            ], 500);
        });
    }

    private function isPosApiRequest(Request $request): bool
    {
        return $request->is('app/pos/drafts*');
    }

    private function traceId(Request $request): string
    {
        return (string) ($request->header('X-Request-Id') ?: Str::uuid());
    }
}
