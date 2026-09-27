<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionExpiryIncidentResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__test/token-mismatch', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        })->name('__test.token-mismatch');

        Route::get('/__test/purchase/token-mismatch', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        })->name('purchase.__test.token-mismatch');

        Route::get('/__test/unrelated-419', function () {
            abort(419, 'Some unrelated 419.');
        })->name('__test.unrelated-419');

        Route::post('/__test/post-only/token-mismatch', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        })->middleware('web')->name('__test.post-only.token-mismatch')->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    public function test_livewire_request_receives_incident_json_contract()
    {
        Log::spy();

        $response = $this->withHeaders(['X-Livewire' => 'true'])
            ->get('/__test/token-mismatch');

        $response->assertStatus(419);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('X-ERP-Incident-ID'));
        $response->assertJsonStructure(['incident_id', 'message', 'transaction_sensitive']);
        $this->assertSame(
            $response->headers->get('X-ERP-Incident-ID'),
            $response->json('incident_id')
        );
    }

    public function test_conventional_request_receives_rendered_419_page_with_incident_id()
    {
        $response = $this->get('/__test/token-mismatch');

        $response->assertStatus(419);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $incidentId = $response->headers->get('X-ERP-Incident-ID');
        $this->assertNotEmpty($incidentId);
        $response->assertSeeText($incidentId);
    }

    public function test_response_incident_id_matches_the_logged_incident_id()
    {
        Log::spy();

        $response = $this->get('/__test/token-mismatch');

        $incidentId = $response->headers->get('X-ERP-Incident-ID');

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) use ($incidentId) {
            return $message === 'csrf_mismatch' && ($context['incident_id'] ?? null) === $incidentId;
        })->once();
    }

    public function test_transaction_sensitive_route_receives_guidance_flag()
    {
        $response = $this->withHeaders(['X-Livewire' => 'true'])
            ->get('/__test/purchase/token-mismatch');

        $response->assertStatus(419);
        $response->assertJson(['transaction_sensitive' => true]);
    }

    public function test_diagnostic_logging_failure_still_returns_valid_419()
    {
        Log::shouldReceive('log')->andThrow(new \RuntimeException('log sink unavailable'));

        $response = $this->get('/__test/token-mismatch');

        $response->assertStatus(419);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('X-ERP-Incident-ID'));
    }

    public function test_unrelated_419_is_not_misclassified_as_csrf_mismatch()
    {
        Log::spy();

        $response = $this->get('/__test/unrelated-419');

        $response->assertStatus(419);
        $this->assertEmpty($response->headers->get('X-ERP-Incident-ID'));

        Log::shouldNotHaveReceived('log', function ($args) {
            return ($args[1] ?? null) === 'csrf_mismatch';
        });
    }

    public function test_csrf_mismatch_event_records_token_presence_and_comparison_evidence()
    {
        Log::spy();

        $this->withSession(['_token' => 'session-token-value'])
            ->post('/__test/post-only/token-mismatch', ['_token' => 'submitted-token-value']);

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
            return $message === 'csrf_mismatch'
                && array_key_exists('submitted_token_present', $context)
                && array_key_exists('session_token_present', $context)
                && array_key_exists('submitted_token_fingerprint', $context)
                && array_key_exists('session_token_fingerprint', $context)
                && array_key_exists('tokens_match', $context)
                && $context['submitted_token_present'] === true
                && $context['session_token_present'] === true
                && $context['tokens_match'] === false;
        })->once();
    }

    public function test_csrf_diagnostic_event_never_contains_raw_token_values()
    {
        Log::spy();

        $this->withSession(['_token' => 'raw-session-token'])
            ->post('/__test/post-only/token-mismatch', ['_token' => 'raw-submitted-token']);

        Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
            if ($message !== 'csrf_mismatch') {
                return true;
            }

            $encoded = json_encode($context);

            return !str_contains($encoded, 'raw-session-token')
                && !str_contains($encoded, 'raw-submitted-token');
        });
    }

    public function test_post_originated_419_does_not_offer_an_unsafe_reload_link()
    {
        $response = $this->post('/__test/post-only/token-mismatch', ['_token' => 'submitted-token-value']);

        $response->assertStatus(419);
        $response->assertDontSee('Muat Ulang Halaman');
    }

    public function test_get_originated_419_offers_a_safe_reload_link()
    {
        $response = $this->get('/__test/token-mismatch');

        $response->assertStatus(419);
        $response->assertSee('Muat Ulang Halaman');
    }
}
