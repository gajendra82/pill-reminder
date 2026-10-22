<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BlynkClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class BlynkReadTest extends TestCase
{
    private const FAKE_TOKEN = 'dedicated-test-device-token';

    private const FAKE_BASE = 'https://blynk.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        Http::preventStrayRequests();

        $this->getJson('/api/device/status?token=injected-token&pin=V1')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);

        Http::assertNothingSent();
    }

    public function test_successful_read_returns_uninterpreted_pin_values(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 16:45:00', 'UTC'));
        $this->actingAsApiUser();
        $this->configureBlynk();

        Http::preventStrayRequests();
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(10, $options['timeout'] ?? null);
            $this->assertSame(5, $options['connect_timeout'] ?? null);
            $this->assertFalse($options['allow_redirects'] ?? null);

            return Http::response([
                'a0' => 1,
                'v0' => 4,
                'v1' => '08:30',
                'v2' => 15,
                'v4' => [12, 11],
                'v8' => 'raw-reading',
                'ignored' => 'not-a-pin',
            ], 200);
        });

        $response = $this->getJson('/api/device/status?pin=V99&token=injected-token&url=https://evil.example');

        $response->assertOk()
            ->assertExactJson([
                'device_status' => 'available',
                'data_source' => 'blynk_live',
                'integration_scope' => 'temporary_single_device',
                'retrieved_at' => '2026-10-22T16:45:00Z',
                'datastreams' => [
                    'a0' => 1,
                    'v0' => 4,
                    'v1' => '08:30',
                    'v2' => 15,
                    'v3' => null,
                    'v4' => [12, 11],
                    'v5' => null,
                    'v6' => null,
                    'v7' => null,
                    'v8' => 'raw-reading',
                    'v9' => null,
                ],
                'notice' => 'Temporary single-device integration. Datastream values are uninterpreted Blynk readings and are not a medication record.',
            ]);

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $response->getContent());
        $this->assertStringNotContainsString('injected-token', $response->getContent());
        $this->assertStringNotContainsString('evil.example', $response->getContent());

        foreach (['hour', 'minute', 'taken', 'missed', 'sensor', 'dose', 'schedule'] as $meaning) {
            $this->assertStringNotContainsString($meaning, $response->getContent());
        }

        $this->assertReadOnlyGetAll();
        $this->assertClientExposesOnlyRead();
    }

    public function test_status_returns_dashboard_pin_numbers_without_writing(): void
    {
        $this->actingAsApiUser();
        $this->configureBlynk();
        Http::preventStrayRequests();
        Http::fake([
            self::FAKE_BASE.'/external/api/getAll*' => Http::response([
                'v1' => 12,
                'v2' => 25,
                'v3' => 16,
                'v4' => 26,
                'v5' => 16,
                'v6' => 50,
                'v7' => 1,
                'v8' => 2,
                'v9' => 3,
            ], 200),
        ]);

        $response = $this->getJson('/api/device/status');

        $response->assertOk()
            ->assertJsonPath('datastreams.v1', 12)
            ->assertJsonPath('datastreams.v2', 25)
            ->assertJsonPath('datastreams.v3', 16)
            ->assertJsonPath('datastreams.v4', 26)
            ->assertJsonPath('datastreams.v5', 16)
            ->assertJsonPath('datastreams.v6', 50)
            ->assertJsonPath('notice', 'Temporary single-device integration. Datastream values are uninterpreted Blynk readings and are not a medication record.');

        $this->assertReadOnlyGetAll();
    }

    public function test_token_echoed_by_blynk_is_removed_from_the_response(): void
    {
        $this->actingAsApiUser();
        $this->configureBlynk();
        Http::preventStrayRequests();
        Http::fake([
            self::FAKE_BASE.'/external/api/getAll*' => Http::response([
                'v1' => self::FAKE_TOKEN,
                'v2' => ['nested' => 'prefix-'.self::FAKE_TOKEN],
            ], 200),
        ]);

        $response = $this->getJson('/api/device/status');

        $response->assertOk()
            ->assertJsonPath('datastreams.v1', null)
            ->assertJsonPath('datastreams.v2.nested', null);

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $response->getContent());
        $this->assertReadOnlyGetAll();
    }

    #[DataProvider('incompleteConfiguration')]
    public function test_incomplete_configuration_is_rejected(string $baseUrl, string $token): void
    {
        $this->actingAsApiUser();
        $this->configureBlynk($baseUrl, $token);
        Http::preventStrayRequests();

        $response = $this->getJson('/api/device/status');

        $response->assertStatus(503)
            ->assertExactJson([
                'message' => 'Device status is unavailable because Blynk is not configured.',
                'error' => 'blynk_not_configured',
            ]);

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        Http::assertNothingSent();
    }

    public static function incompleteConfiguration(): array
    {
        return [
            'missing both' => ['', ''],
            'missing token' => [self::FAKE_BASE, ''],
            'missing url' => ['', self::FAKE_TOKEN],
            'http url' => ['http://blynk.test', self::FAKE_TOKEN],
            'url with path' => ['https://blynk.test/external/api', self::FAKE_TOKEN],
            'url with query' => ['https://blynk.test?token=other', self::FAKE_TOKEN],
            'url with userinfo' => ['https://user:secret@blynk.test', self::FAKE_TOKEN],
        ];
    }

    #[DataProvider('upstreamFailures')]
    public function test_upstream_failures_do_not_leak_credentials(
        int $status,
        mixed $body,
        array $headers,
        int $expectedStatus,
        string $expectedError,
    ): void {
        $this->actingAsApiUser();
        $this->configureBlynk();
        Http::preventStrayRequests();
        Http::fake([
            self::FAKE_BASE.'/external/api/getAll*' => Http::response($body, $status, $headers),
        ]);

        $response = $this->getJson('/api/device/status');

        $response->assertStatus($expectedStatus)
            ->assertExactJson([
                'message' => $expectedError === 'blynk_rate_limited'
                    ? 'Device status is temporarily rate limited.'
                    : 'Device status could not be retrieved.',
                'error' => $expectedError,
            ]);

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $response->getContent());
        $this->assertStringNotContainsString('ny3.blynk.cloud', $response->getContent());
        $this->assertStringNotContainsString('Invalid token.', $response->getContent());
        $this->assertReadOnlyGetAll();
    }

    public static function upstreamFailures(): array
    {
        $location = 'https://ny3.blynk.cloud/external/api/getAll?token='.self::FAKE_TOKEN;

        return [
            'invalid token' => [400, ['error' => ['message' => 'Invalid token.']], [], 502, 'blynk_unauthorized'],
            'unauthorized' => [401, ['error' => ['message' => 'Invalid token.']], [], 502, 'blynk_unauthorized'],
            'forbidden' => [403, ['error' => ['message' => 'Invalid token.']], [], 502, 'blynk_unauthorized'],
            'rate limit' => [429, ['error' => ['message' => 'Too many requests']], [], 429, 'blynk_rate_limited'],
            'server error' => [500, ['error' => ['message' => 'Invalid token. '.self::FAKE_TOKEN]], [], 502, 'blynk_unavailable'],
            'redirect' => [308, '', ['Location' => $location], 502, 'blynk_unavailable'],
        ];
    }

    public function test_error_object_without_pins_is_an_authorization_failure(): void
    {
        $this->actingAsApiUser();
        $this->configureBlynk();
        Http::preventStrayRequests();
        Http::fake([
            self::FAKE_BASE.'/external/api/getAll*' => Http::response([
                'error' => ['message' => 'Invalid token.'],
            ], 200),
        ]);

        $this->getJson('/api/device/status')
            ->assertStatus(502)
            ->assertExactJson([
                'message' => 'Device status could not be retrieved.',
                'error' => 'blynk_unauthorized',
            ]);

        $this->assertReadOnlyGetAll();
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_json_is_rejected(string $body): void
    {
        $this->actingAsApiUser();
        $this->configureBlynk();
        Http::preventStrayRequests();
        Http::fake([
            self::FAKE_BASE.'/external/api/getAll*' => Http::response($body, 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $response = $this->getJson('/api/device/status');

        $response->assertStatus(502)
            ->assertExactJson([
                'message' => 'Device status could not be retrieved.',
                'error' => 'blynk_invalid_response',
            ]);

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $response->getContent());
        $this->assertReadOnlyGetAll();
    }

    public static function invalidBodies(): array
    {
        return [
            'text' => ['not-json '.self::FAKE_TOKEN],
            'null' => ['null'],
            'list' => ['[1,2]'],
            'string' => ['"v1"'],
        ];
    }

    public function test_connection_failure_is_hidden(): void
    {
        $this->actingAsApiUser();
        $this->configureBlynk();
        Http::preventStrayRequests();
        Http::fake(function () {
            return Http::failedConnection('cURL error 28: timed out for '.self::FAKE_BASE.self::FAKE_PATH().'?token='.self::FAKE_TOKEN);
        });

        $response = $this->getJson('/api/device/status');

        $response->assertStatus(504)
            ->assertExactJson([
                'message' => 'Device status could not be retrieved.',
                'error' => 'blynk_connection_failed',
            ]);

        $this->assertStringNotContainsString(self::FAKE_TOKEN, $response->getContent());
        $this->assertStringNotContainsString('blynk.test', $response->getContent());
        $this->assertReadOnlyGetAll();
    }

    public function test_missing_pins_are_null_and_present_pins_are_kept(): void
    {
        $this->actingAsApiUser();
        $this->configureBlynk();
        Http::preventStrayRequests();
        Http::fake([
            self::FAKE_BASE.'/external/api/getAll*' => Http::response([
                'v2' => 'only-this',
            ], 200),
        ]);

        $datastreams = $this->getJson('/api/device/status')
            ->assertOk()
            ->json('datastreams');

        $this->assertSame('only-this', $datastreams['v2']);

        foreach (['v1', 'v3', 'v4', 'v5', 'v6', 'v7', 'v8', 'v9'] as $pin) {
            $this->assertArrayHasKey($pin, $datastreams);
            $this->assertNull($datastreams[$pin]);
        }

        $this->assertReadOnlyGetAll();
    }

    private function actingAsApiUser(): void
    {
        $user = new User;
        $user->id = 1;
        $user->name = 'Test User';
        $user->email = 'test@example.com';

        Sanctum::actingAs($user, ['*']);
    }

    private function configureBlynk(string $baseUrl = self::FAKE_BASE, string $token = self::FAKE_TOKEN): void
    {
        config([
            'services.blynk.base_url' => $baseUrl,
            'services.blynk.auth_token' => $token,
        ]);
    }

    private function assertReadOnlyGetAll(): void
    {
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_SCHEME) === 'https'
                && parse_url($request->url(), PHP_URL_HOST) === 'blynk.test'
                && parse_url($request->url(), PHP_URL_PATH) === BlynkClient::READ_PATH
                && array_keys($query) === ['token']
                && $query['token'] === self::FAKE_TOKEN;
        });
        Http::assertNotSent(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return $request->method() !== 'GET' || str_contains($path, 'update');
        });
    }

    private function assertClientExposesOnlyRead(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            array_values(array_filter(
                (new ReflectionClass(BlynkClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === BlynkClient::class
                    && $method->getName() !== '__construct'
            ))
        );

        $this->assertSame(['readDatastreams', 'updateDatastream'], $methods);
        $this->assertSame('/external/api/getAll', BlynkClient::READ_PATH);
    }

    private static function FAKE_PATH(): string
    {
        return BlynkClient::READ_PATH;
    }
}
