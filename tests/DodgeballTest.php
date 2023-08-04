<?php
namespace Dodgeball\DodgeballSdkServer;

require_once __DIR__ . '/../vendor/autoload.php';

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\RequestException;

use PHPUnit\Framework\TestCase;

final class DodgeballTest extends TestCase {
  private $dodgeball;

  protected function setUp(): void {
    $this->dodgeball = new Dodgeball('secret_key', [
      'apiUrl' => 'https://api.example.com',
      'apiVersion' => DodgeballApiVersion::v1,
    ]);
  }

  public function testConstructorWithSecretKey(): void {
    $this->assertInstanceOf(Dodgeball::class, $this->dodgeball);
  }

  public function testConstructorWithoutSecretKey(): void {
    $this->expectException(Exception::class);
    new Dodgeball('');
  }

  public function testConstructorWithConfig(): void {
    $dodgeball = new Dodgeball('secret_key', []);
    $this->assertInstanceOf(Dodgeball::class, $dodgeball);
  }

  public function testConstructorWithoutConfig(): void {
    $dodgeball = new Dodgeball('secret_key');
    $this->assertInstanceOf(Dodgeball::class, $dodgeball);
  }

  public function testConstructApiUrlWithEndpoint(): void {
    $expected = 'https://api.example.com/v1/test';
    $actual = $this->dodgeball->constructApiUrl('test');
    $this->assertEquals($expected, $actual);
  }

  public function testConstructApiUrlWithoutEndpoint(): void {
    $expected = 'https://api.example.com/v1/';
    $actual = $this->dodgeball->constructApiUrl();
    $this->assertEquals($expected, $actual);
  }

  public function testConstructApiHeadersWithAllParams(): void {
    $expected = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Verification-Id" => "verification_id",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "customer_id",
      "Dodgeball-Session-Id" => "session_id",
    ];
    $actual = $this->dodgeball->constructApiHeaders("verification_id", "source_token", "customer_id", "session_id");
    $this->assertEquals($expected, $actual);
  }

  public function testConstructApiHeadersWithNoParams(): void {
    $expected = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
    ];
    $actual = $this->dodgeball->constructApiHeaders();
    $this->assertEquals($expected, $actual);
  }

  public function testConstructApiHeadersWithSomeParams(): void {
    $expected = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Verification-Id" => "verification_id",
      "Dodgeball-Customer-Id" => "customer_id",
    ];
    $actual = $this->dodgeball->constructApiHeaders("verification_id", "", "customer_id", "");
    $this->assertEquals($expected, $actual);
  }

  public function testCreateErrorResponseWithCodeAndMessage(): void {
    $expected = new DodgeballCheckpointResponse(
      false,
      [
        (object) [
          'code' => 404,
          'message' => 'Not found',
        ],
      ],
      DodgeballApiVersion::v1,
      new DodgeballVerification(
        '',
        VerificationStatus::FAILED,
        VerificationOutcome::ERROR
      )
    );
    $actual = $this->dodgeball->createErrorResponse(404, 'Not found');
    $this->assertEquals($expected, $actual);
  }

  public function testCreateErrorResponseWithCodeOnly(): void {
    $expected = new DodgeballCheckpointResponse(
      false,
      [
        (object) [
          'code' => 500,
          'message' => 'Unknown evaluation error',
        ],
      ],
      DodgeballApiVersion::v1,
      new DodgeballVerification(
        '',
        VerificationStatus::FAILED,
        VerificationOutcome::ERROR
      )
    );
    $actual = $this->dodgeball->createErrorResponse(500);
    $this->assertEquals($expected, $actual);
  }

  public function testCreateErrorResponseWithNoParams(): void {
    $expected = new DodgeballCheckpointResponse(
      false,
      [
        (object) [
          'code' => 500,
          'message' => 'Unknown evaluation error',
        ],
      ],
      DodgeballApiVersion::v1,
      new DodgeballVerification(
        '',
        VerificationStatus::FAILED,
        VerificationOutcome::ERROR
      )
    );
    $actual = $this->dodgeball->createErrorResponse();
    $this->assertEquals($expected, $actual);
  }

  public function testEvent(): void {
    // Set up the mock handler
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], '{"success": true, "errors": []}'),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $this->dodgeball->setHandlerStack($handlerStack);

    // Make the call
    $this->dodgeball->event([
      'userId' => 'user_id',
      'sessionId' => 'session_id',
      'sourceToken' => 'source_token',
      'event' => (object) [
        'type' => 'EVENT_NAME',
        'data' => (object) [
          'key' => 'value',
          'nested' => (object) [
            'key' => 'nestedValue',
          ],
        ],
        'eventTime' => 123
      ]
    ]);

    // Assert the request
    $this->assertCount(1, $container);
    $transaction = $container[0];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/track', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("EVENT_NAME", $requestJsonBody->type);
    $this->assertEquals(123, $requestJsonBody->eventTime);
    $this->assertEquals("value", $requestJsonBody->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->data->nested->key);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);
  }

  public function testCheckpointVerificationAllowed(): void {
    // Set up the mock handler
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::COMPLETE,
          'outcome' => VerificationOutcome::APPROVED,
        ],
      ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $this->dodgeball->setHandlerStack($handlerStack);

    // Make the call
    $dodgeballCheckpointResponse = $this->dodgeball->checkpoint([
      'checkpointName' => 'CHECKPOINT_NAME',
      'event' => (object) [
        'ip' => '127.0.0.1',
        'data' => (object) [
          'key' => 'value',
          'nested' => (object) [
            'key' => 'nestedValue',
          ],
        ],
      ],
      'sourceToken' => 'source_token',
      'sessionId' => 'session_id',
      'userId' => 'user_id',
      'useVerificationId' => 'verification_id'
    ]);

    // Assert the requests
    $this->assertCount(3, $container);

    // Assert the first request
    $transaction = $container[0];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the second request
    $transaction = $container[1];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the third request
    $transaction = $container[2];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);
    $this->assertEquals(VerificationStatus::COMPLETE->value, $responseJsonBody->verification->status);

    // Validate that an successful response was returned
    $this->assertEquals(true, $dodgeballCheckpointResponse->success);
    $this->assertEquals(0, count($dodgeballCheckpointResponse->errors));
    $this->assertEquals(VerificationStatus::COMPLETE, $dodgeballCheckpointResponse->verification->status);
    $this->assertEquals(VerificationOutcome::APPROVED, $dodgeballCheckpointResponse->verification->outcome);
    $this->assertEquals(false, $dodgeballCheckpointResponse->isRunning());
    $this->assertEquals(true, $dodgeballCheckpointResponse->isAllowed());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isDenied());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isUndecided());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isTimeout());
    $this->assertEquals(false, $dodgeballCheckpointResponse->hasError());
  }

  public function testCheckpointVerificationDenied(): void {
    // Set up the mock handler
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::COMPLETE,
          'outcome' => VerificationOutcome::DENIED,
        ],
      ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $this->dodgeball->setHandlerStack($handlerStack);

    // Make the call
    $dodgeballCheckpointResponse = $this->dodgeball->checkpoint([
      'checkpointName' => 'CHECKPOINT_NAME',
      'event' => (object) [
        'ip' => '127.0.0.1',
        'data' => (object) [
          'key' => 'value',
          'nested' => (object) [
            'key' => 'nestedValue',
          ],
        ],
      ],
      'sourceToken' => 'source_token',
      'sessionId' => 'session_id',
      'userId' => 'user_id',
      'useVerificationId' => 'verification_id',
      'options' => (object) [
        'sync' => true,
        'timeout' => 12345,
      ],
    ]);

    // Assert the requests
    $this->assertCount(3, $container);

    // Assert the first request
    $transaction = $container[0];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the second request
    $transaction = $container[1];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the third request
    $transaction = $container[2];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);
    $this->assertEquals(VerificationStatus::COMPLETE->value, $responseJsonBody->verification->status);

    // Validate that an successful response was returned
    $this->assertEquals(true, $dodgeballCheckpointResponse->success);
    $this->assertEquals(0, count($dodgeballCheckpointResponse->errors));
    $this->assertEquals(VerificationStatus::COMPLETE, $dodgeballCheckpointResponse->verification->status);
    $this->assertEquals(VerificationOutcome::DENIED, $dodgeballCheckpointResponse->verification->outcome);
    $this->assertEquals(false, $dodgeballCheckpointResponse->isRunning());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isAllowed());
    $this->assertEquals(true, $dodgeballCheckpointResponse->isDenied());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isUndecided());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isTimeout());
    $this->assertEquals(false, $dodgeballCheckpointResponse->hasError());
  }

  public function testCheckpointInitialError(): void {
    // Set up the mock handler
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => [],
        'version' => 'v1',
        'verification' => null
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => [],
        'version' => 'v1',
        'verification' => null
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => ["You have exceeded your usage limits for this billing cycle. Please go to the billing page at https://app.dodgeballhq.com/settings?tab=usage to resolve this issue."],
        'version' => 'v1',
        'verification' => null
      ])),
      // This response should not be called
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => [],
        'version' => 'v1',
        'verification' => null
      ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $this->dodgeball->setHandlerStack($handlerStack);

    // Make the call
    $dodgeballCheckpointResponse = $this->dodgeball->checkpoint([
      'checkpointName' => 'CHECKPOINT_NAME',
      'event' => (object) [
        'ip' => '127.0.0.1',
        'data' => (object) [
          'key' => 'value',
          'nested' => (object) [
            'key' => 'nestedValue',
          ],
        ],
      ],
      'sourceToken' => 'source_token',
      'sessionId' => 'session_id',
      'userId' => 'user_id',
      'useVerificationId' => 'verification_id',
      'options' => (object) [
        'sync' => true,
        'timeout' => 12345,
      ],
    ]);

    // Assert the requests
    $this->assertCount(3, $container);

    // Assert the first request
    $transaction = $container[0];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the second request
    $transaction = $container[1];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the third request
    $transaction = $container[2];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals("You have exceeded your usage limits for this billing cycle. Please go to the billing page at https://app.dodgeballhq.com/settings?tab=usage to resolve this issue.", $responseJsonBody->errors[0]);

    // Validate that an error response was returned
    $this->assertEquals(false, $dodgeballCheckpointResponse->success);
    $this->assertEquals(1, count($dodgeballCheckpointResponse->errors));
    $this->assertEquals("You have exceeded your usage limits for this billing cycle. Please go to the billing page at https://app.dodgeballhq.com/settings?tab=usage to resolve this issue.", $dodgeballCheckpointResponse->errors[0]);
    $this->assertEquals(VerificationStatus::FAILED, $dodgeballCheckpointResponse->verification->status);
    $this->assertEquals(VerificationOutcome::ERROR, $dodgeballCheckpointResponse->verification->outcome);
    $this->assertEquals(false, $dodgeballCheckpointResponse->isRunning());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isAllowed());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isDenied());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isUndecided());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isTimeout());
    $this->assertEquals(true, $dodgeballCheckpointResponse->hasError());
  }

  public function testCheckpointVerificationError(): void {
    // Set up the mock handler
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => [],
        'version' => 'v1',
        'verification' => null
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => [],
        'version' => 'v1',
        'verification' => null
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => ["You have exceeded your usage limits for this billing cycle. Please go to the billing page at https://app.dodgeballhq.com/settings?tab=usage to resolve this issue."],
        'version' => 'v1',
        'verification' => null
      ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $this->dodgeball->setHandlerStack($handlerStack);

    // Make the call
    $dodgeballCheckpointResponse = $this->dodgeball->checkpoint([
      'checkpointName' => 'CHECKPOINT_NAME',
      'event' => (object) [
        'ip' => '127.0.0.1',
        'data' => (object) [
          'key' => 'value',
          'nested' => (object) [
            'key' => 'nestedValue',
          ],
        ],
      ],
      'sourceToken' => 'source_token',
      'sessionId' => 'session_id',
      'userId' => 'user_id',
      'useVerificationId' => 'verification_id',
      'options' => (object) [
        'sync' => true,
        'timeout' => 1000,
      ],
    ]);

    // Assert the requests
    $this->assertCount(4, $container);

    // Assert the first request
    $transaction = $container[0];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the second request
    $transaction = $container[1];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the third request
    $transaction = $container[2];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the fourth request
    $transaction = $container[3];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals("You have exceeded your usage limits for this billing cycle. Please go to the billing page at https://app.dodgeballhq.com/settings?tab=usage to resolve this issue.", $responseJsonBody->errors[0]);

    // Validate that an error response was returned
    $this->assertEquals(false, $dodgeballCheckpointResponse->success);
    $this->assertEquals(1, count($dodgeballCheckpointResponse->errors));
    $this->assertEquals("You have exceeded your usage limits for this billing cycle. Please go to the billing page at https://app.dodgeballhq.com/settings?tab=usage to resolve this issue.", $dodgeballCheckpointResponse->errors[0]);
    $this->assertEquals(VerificationStatus::FAILED, $dodgeballCheckpointResponse->verification->status);
    $this->assertEquals(VerificationOutcome::ERROR, $dodgeballCheckpointResponse->verification->outcome);
    $this->assertEquals(false, $dodgeballCheckpointResponse->isRunning());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isAllowed());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isDenied());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isUndecided());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isTimeout());
    $this->assertEquals(true, $dodgeballCheckpointResponse->hasError());
  }

  public function testCheckpointVerificationTimeout(): void {
    // Set up the mock handler
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => [],
        'version' => 'v1',
        'verification' => null
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => [],
        'version' => 'v1',
        'verification' => null
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => false,
        'errors' => [],
        'version' => 'v1',
        'verification' => null
      ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $this->dodgeball->setHandlerStack($handlerStack);

    // Make the call
    $dodgeballCheckpointResponse = $this->dodgeball->checkpoint([
      'checkpointName' => 'CHECKPOINT_NAME',
      'event' => (object) [
        'ip' => '127.0.0.1',
        'data' => (object) [
          'key' => 'value',
          'nested' => (object) [
            'key' => 'nestedValue',
          ],
        ],
      ],
      'sourceToken' => 'source_token',
      'sessionId' => 'session_id',
      'userId' => 'user_id',
      'useVerificationId' => 'verification_id',
      'options' => (object) [
        'sync' => true,
        'timeout' => 1000,
      ],
    ]);

    // Assert the requests
    $this->assertCount(4, $container);

    // Assert the first request
    $transaction = $container[0];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the second request
    $transaction = $container[1];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the third request
    $transaction = $container[2];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the fourth request
    $transaction = $container[3];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(false, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Validate that an error response was returned
    $this->assertEquals(false, $dodgeballCheckpointResponse->success);
    $this->assertEquals(1, count($dodgeballCheckpointResponse->errors));
    $this->assertEquals(503, $dodgeballCheckpointResponse->errors[0]->code);
    $this->assertEquals(VerificationStatus::FAILED, $dodgeballCheckpointResponse->verification->status);
    $this->assertEquals(VerificationOutcome::ERROR, $dodgeballCheckpointResponse->verification->outcome);
    $this->assertEquals(false, $dodgeballCheckpointResponse->isRunning());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isAllowed());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isDenied());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isUndecided());
    $this->assertEquals(true, $dodgeballCheckpointResponse->isTimeout());
    $this->assertEquals(true, $dodgeballCheckpointResponse->hasError());
  }

  public function testCheckpointVerificationPending(): void {
    // Set up the mock handler
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      // This request should not be called
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $this->dodgeball->setHandlerStack($handlerStack);

    // Make the call
    $dodgeballCheckpointResponse = $this->dodgeball->checkpoint([
      'checkpointName' => 'CHECKPOINT_NAME',
      'event' => (object) [
        'ip' => '127.0.0.1',
        'data' => (object) [
          'key' => 'value',
          'nested' => (object) [
            'key' => 'nestedValue',
          ],
        ],
      ],
      'sourceToken' => 'source_token',
      'sessionId' => 'session_id',
      'userId' => 'user_id',
      'useVerificationId' => 'verification_id',
      'options' => (object) [
        'sync' => true,
        'timeout' => 1000,
      ],
    ]);

    // Assert the requests
    $this->assertCount(4, $container);

    // Assert the first request
    $transaction = $container[0];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the second request
    $transaction = $container[1];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the third request
    $transaction = $container[2];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);
    $this->assertEquals(VerificationStatus::PENDING->value, $responseJsonBody->verification->status);

    // Validate that a pending response was returned
    $this->assertEquals(true, $dodgeballCheckpointResponse->success);
    $this->assertEquals(0, count($dodgeballCheckpointResponse->errors));
    $this->assertEquals(VerificationStatus::PENDING, $dodgeballCheckpointResponse->verification->status);
    $this->assertEquals(VerificationOutcome::PENDING, $dodgeballCheckpointResponse->verification->outcome);
    $this->assertEquals(true, $dodgeballCheckpointResponse->isRunning());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isAllowed());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isDenied());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isUndecided());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isTimeout());
    $this->assertEquals(false, $dodgeballCheckpointResponse->hasError());
  }
  
  public function testCheckpointVerificationUndecided(): void {
    // Set up the mock handler
    $container = [];
    $history = Middleware::history($container);
    $mock = new MockHandler([
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::PENDING,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::COMPLETE,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
      // This request should not be called
      new Response(200, ['Content-Type' => 'application/json'], json_encode((object) [
        'success' => true,
        'errors' => [],
        'version' => 'v1',
        'verification' => (object) [
          'id' => 'verification_id',
          'status' => VerificationStatus::COMPLETE,
          'outcome' => VerificationOutcome::PENDING,
        ],
      ])),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push($history);
    $this->dodgeball->setHandlerStack($handlerStack);

    // Make the call
    $dodgeballCheckpointResponse = $this->dodgeball->checkpoint([
      'checkpointName' => 'CHECKPOINT_NAME',
      'event' => (object) [
        'ip' => '127.0.0.1',
        'data' => (object) [
          'key' => 'value',
          'nested' => (object) [
            'key' => 'nestedValue',
          ],
        ],
      ],
      'sourceToken' => 'source_token',
      'sessionId' => 'session_id',
      'userId' => 'user_id',
      'useVerificationId' => 'verification_id',
      'options' => (object) [
        'sync' => true,
        'timeout' => 1000,
      ],
    ]);

    // Assert the requests
    $this->assertCount(4, $container);

    // Assert the first request
    $transaction = $container[0];
    $request = $transaction['request'];
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/checkpoint', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the request body
    $requestJsonBody = json_decode($request->getBody());

    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->checkpointName);
    $this->assertEquals("CHECKPOINT_NAME", $requestJsonBody->event->type);
    $this->assertEquals("127.0.0.1", $requestJsonBody->event->ip);
    $this->assertEquals("value", $requestJsonBody->event->data->key);
    $this->assertEquals("nestedValue", $requestJsonBody->event->data->nested->key);
    $this->assertEquals(true, $requestJsonBody->options->sync);

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the second request
    $transaction = $container[1];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);

    // Assert the third request
    $transaction = $container[2];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);
    $this->assertEquals(VerificationStatus::PENDING->value, $responseJsonBody->verification->status);

    // Assert the fourth request
    $transaction = $container[3];
    $request = $transaction['request'];
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals('https://api.example.com/v1/verification/verification_id', $request->getUri());

    $expectedHeaders = [
      "Dodgeball-Secret-Key" => "secret_key",
      "Content-Type" => "application/json",
      "Dodgeball-Source-Token" => "source_token",
      "Dodgeball-Customer-Id" => "user_id",
      "Dodgeball-Session-Id" => "session_id",
      "Dodgeball-Verification-Id" => "verification_id",
    ];
    // Iterate through the expected headers and assert that they are present
    foreach ($expectedHeaders as $key => $value) {
      $this->assertEquals($value, $request->getHeader($key)[0]);
    }

    // Assert the response
    $response = $transaction['response'];
    $this->assertEquals(200, $response->getStatusCode());

    $responseJsonBody = json_decode($response->getBody());
    $this->assertEquals(true, $responseJsonBody->success);
    $this->assertEquals([], $responseJsonBody->errors);
    $this->assertEquals(VerificationStatus::COMPLETE->value, $responseJsonBody->verification->status);

    // Validate that a pending response was returned
    $this->assertEquals(true, $dodgeballCheckpointResponse->success);
    $this->assertEquals(0, count($dodgeballCheckpointResponse->errors));
    $this->assertEquals(VerificationStatus::COMPLETE, $dodgeballCheckpointResponse->verification->status);
    $this->assertEquals(VerificationOutcome::PENDING, $dodgeballCheckpointResponse->verification->outcome);
    $this->assertEquals(false, $dodgeballCheckpointResponse->isRunning());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isAllowed());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isDenied());
    $this->assertEquals(true, $dodgeballCheckpointResponse->isUndecided());
    $this->assertEquals(false, $dodgeballCheckpointResponse->isTimeout());
    $this->assertEquals(false, $dodgeballCheckpointResponse->hasError());
  }
}
?>