<?php

namespace Tests\Unit\Services;

use App\Services\SessionIncidentDiagnosticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SessionIncidentDiagnosticsServiceTest extends TestCase
{
    private SessionIncidentDiagnosticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SessionIncidentDiagnosticsService();
    }

    public function test_incident_id_has_expected_shape()
    {
        $id = $this->service->generateIncidentId();

        $this->assertMatchesRegularExpression('/^ERP-[A-Z0-9]{10}$/', $id);
        $this->assertNotSame($id, $this->service->generateIncidentId());
    }

    public function test_fingerprint_is_deterministic_for_the_same_value()
    {
        $a = $this->service->fingerprint('some-session-id');
        $b = $this->service->fingerprint('some-session-id');

        $this->assertSame($a, $b);
        $this->assertStringStartsWith(SessionIncidentDiagnosticsService::FINGERPRINT_VERSION . ':', $a);
    }

    public function test_fingerprint_differs_for_different_values()
    {
        $a = $this->service->fingerprint('session-a');
        $b = $this->service->fingerprint('session-b');

        $this->assertNotSame($a, $b);
    }

    public function test_fingerprint_never_contains_the_raw_value()
    {
        $raw = 'super-secret-session-id-value';
        $fingerprint = $this->service->fingerprint($raw);

        $this->assertStringNotContainsString($raw, $fingerprint);
    }

    public function test_fingerprint_of_null_or_empty_is_null()
    {
        $this->assertNull($this->service->fingerprint(null));
        $this->assertNull($this->service->fingerprint(''));
    }

    public function test_context_values_are_length_limited()
    {
        $long = str_repeat('a', 500);

        $normalized = $this->service->normalizeContextValue($long);

        $this->assertLessThanOrEqual(100, strlen($normalized));
    }

    public function test_context_values_strip_control_characters()
    {
        $value = "line1\x00\x1Fline2\n";

        $normalized = $this->service->normalizeContextValue($value);

        $this->assertStringNotContainsString("\x00", $normalized);
        $this->assertStringNotContainsString("\x1F", $normalized);
    }

    public function test_transaction_route_classification()
    {
        $this->assertTrue($this->service->isTransactionSensitiveRoute('purchase.create'));
        $this->assertTrue($this->service->isTransactionSensitiveRoute('pos.checkout'));
        $this->assertTrue($this->service->isTransactionSensitiveRoute('sales.return.store'));
        $this->assertTrue($this->service->isTransactionSensitiveRoute('stock.transfer'));
        $this->assertTrue($this->service->isTransactionSensitiveRoute('payment.method.update'));
        $this->assertTrue($this->service->isTransactionSensitiveRoute('receiving.confirm'));

        $this->assertFalse($this->service->isTransactionSensitiveRoute('dashboard'));
        $this->assertFalse($this->service->isTransactionSensitiveRoute(null));
    }

    public function test_request_context_excludes_raw_session_id_csrf_token_and_body()
    {
        $request = Request::create('/purchase/create', 'POST', [
            'password' => 'secret',
            '_token' => 'raw-csrf-token-value',
        ]);
        $request->setLaravelSession($this->app['session']->driver());
        $request->headers->set('Cookie', 'laravel_session=raw-session-cookie-value');

        $context = $this->service->requestContext($request);

        $encoded = json_encode($context);

        $this->assertStringNotContainsString('raw-csrf-token-value', $encoded);
        $this->assertStringNotContainsString('raw-session-cookie-value', $encoded);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertArrayNotHasKey('cookies', $context);
        $this->assertArrayNotHasKey('headers', $context);
        $this->assertArrayNotHasKey('body', $context);
    }

    public function test_client_page_context_only_reads_allowlisted_headers()
    {
        $request = Request::create('/livewire/update', 'POST');
        $request->headers->set('X-ERP-Page-View-Id', 'view-123');
        $request->headers->set('X-ERP-Tab-Id', 'tab-456');
        $request->headers->set('X-Livewire-Component-Payload', 'should-not-appear');

        $context = $this->service->clientPageContext($request);

        $this->assertSame('view-123', $context['page_view_id']);
        $this->assertSame('tab-456', $context['tab_id']);
        $this->assertArrayNotHasKey('component_payload', $context);
    }

    public function test_record_swallows_logging_failures()
    {
        Log::shouldReceive('log')->once()->andThrow(new \RuntimeException('log sink down'));

        $this->service->record('csrf_mismatch', ['incident_id' => 'ERP-TEST']);

        $this->assertTrue(true);
    }
}
