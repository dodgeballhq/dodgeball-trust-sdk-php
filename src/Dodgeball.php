<?php
namespace Dodgeball\DodgeballSdkServer;

use Exception;

const BASE_CHECKPOINT_TIMEOUT_MS = 100;
const MAX_TIMEOUT = 10000;
const MAX_RETRY_COUNT = 3;

enum DodgeballApiVersion: string {
  case v1 = 'v1';
}

enum VerificationStatus: string {
  case PENDING = 'PENDING';
  case BLOCKED = 'BLOCKED';
  case COMPLETE = 'COMPLETE';
  case FAILED = 'FAILED';
}

enum VerificationOutcome: string {
  case APPROVED = 'APPROVED';
  case DENIED = 'DENIED';
  case PENDING = 'PENDING';
  case ERROR = 'ERROR';
}

class DodgeballConfig
{
  public string $apiUrl = 'https://api.dodgeballhq.com/';
  public DodgeballApiVersion $apiVersion = DodgeballApiVersion::v1;
  public bool $isEnabled = true;

  // All properties are optional parameters to the constructor
  function __construct(
    string $apiUrl = null,
    DodgeballApiVersion $apiVersion = null,
    bool $isEnabled = true
  ) {
    if ($apiVersion) {
      $this->apiVersion = $apiVersion;
    }
    if ($apiUrl) {
      // Replace the last character with a slash if it's not already there
      if (substr($apiUrl, -1) !== '/') {
        $apiUrl .= '/';
      }

      $this->apiUrl = $apiUrl;
    }
    if ($isEnabled) {
      $this->isEnabled = $isEnabled;
    }
  }
}

class EventParams
{
  public string $userId = '';
  public string $sessionId = '';
  public string $sourceToken = '';
  public TrackEvent $event;

  function __construct(
    string $userId = '',
    string $sessionId = '',
    string $sourceToken = '',
    TrackEvent $event = null
  ) {
    $this->userId = $userId;
    $this->sessionId = $sessionId;
    $this->sourceToken = $sourceToken;
    $this->event = $event;
  }
}

class TrackEvent
{
  public string $type = '';
  public int $eventTime = 0;
  public object $data;

  function __construct(string $type = '', object $data = null, int $eventTime = 0) {
    if (!$eventTime) {
      $this->eventTime = time();
    } else {
      $this->eventTime = $eventTime;
    }
    $this->type = $type;
    $this->data = $data ?? new \stdClass();
  }
}

class CheckpointEvent
{
  public string $ip = '';
  public object $data;

  function __construct(string $ip = '', object $data = null) {
    $this->ip = $ip;
    $this->data = $data ?? new \stdClass();
  }
}

class CheckpointResponseOptions
{
  public bool $sync = true;
  public int $timeout = 0;
  public string $webhook = '';

  function __construct(bool $sync = true, int $timeout = 0, string $webhook = '') {
    $this->sync = $sync;
    $this->timeout = $timeout;
    $this->webhook = $webhook;
  }
}

class CheckpointParams
{
  public string $checkpointName = '';
  public CheckpointEvent $event;
  public string $sourceToken = '';
  public string $sessionId = '';
  public string $userId = '';
  public string $useVerificationId = '';
  public CheckpointResponseOptions $options;

  function __construct(string $checkpointName, CheckpointEvent $event, string $sourceToken = '', string $sessionId = '', string $userId = '', string $useVerificationId = '', CheckpointResponseOptions $options = null) {
    $this->checkpointName = $checkpointName;
    $this->event = $event;
    $this->sourceToken = $sourceToken;
    $this->sessionId = $sessionId;
    $this->userId = $userId;
    $this->useVerificationId = $useVerificationId;
    $this->options = $options ?? new CheckpointResponseOptions();
  }
}

class DodgeballMissingParameterError extends Exception
{
  function __construct(string $parameterName, string $parameterValue) {
    parent::__construct("Missing required parameter: " . $parameterName . " with value: " . $parameterValue);
  }
}

class DodgeballVerification
{
  public string $id = '';
  public VerificationStatus $status = VerificationStatus::PENDING;
  public VerificationOutcome $outcome = VerificationOutcome::PENDING;

  function __construct(string $id = '', VerificationStatus $status = VerificationStatus::PENDING, VerificationOutcome $outcome = VerificationOutcome::PENDING) {
    $this->id = $id;
    $this->status = $status;
    $this->outcome = $outcome;
  }
}

class DodgeballCheckpointResponse
{
  public bool $success = false;
  public array $errors = [];
  public DodgeballApiVersion $version = DodgeballApiVersion::v1;
  public DodgeballVerification $verification;
  public bool $isTimeout = false;

  function __construct(bool $success = false, array $errors = [], DodgeballApiVersion $version = DodgeballApiVersion::v1, DodgeballVerification $verification = null, bool $isTimeout = false) {
    $this->success = $success;
    $this->errors = $errors;
    $this->version = $version;
    $this->verification = $verification ?? new DodgeballVerification();
    $this->isTimeout = $isTimeout;
  }

  public function isRunning(): bool {
    if ($this->success) {
      switch ($this->verification->status ?? null) {
        case VerificationStatus::PENDING:
        case VerificationStatus::BLOCKED:
          return true;
        default:
          return false;
      }
    }

    return false;
  }

  public function isAllowed(): bool {
    return (
      $this->success &&
      $this->verification->status === VerificationStatus::COMPLETE &&
      $this->verification->outcome === VerificationOutcome::APPROVED
    );
  }

  public function isDenied(): bool {
    if ($this->success) {
      switch ($this->verification->outcome ?? null) {
        case VerificationOutcome::DENIED:
          return true;
        default:
          return false;
      }
    }

    return false;
  }

  public function isUndecided(): bool {
    return (
      $this->success &&
      $this->verification->status === VerificationStatus::COMPLETE &&
      $this->verification->outcome === VerificationOutcome::PENDING
    );
  }

  public function hasError(): bool {
    return (
      !$this->success &&
      (($this->verification->status === VerificationStatus::FAILED &&
        $this->verification->outcome === VerificationOutcome::ERROR) ||
        count($this->errors) > 0)
    );
  }

  public function isTimeout(): bool {
    return (
      !$this->success && ($this->isTimeout ?? false)
    );
  }
}

class Dodgeball
{
  protected string $secretKey;
  protected DodgeballConfig $config;
  protected ?\GuzzleHttp\HandlerStack $handlerStack = null;

  function __construct(string $secretKey, array $options = []) {
    if (!$secretKey) {
      throw new DodgeballMissingParameterError('secretKey', $secretKey);
    }

    // Default config if not provided
    $config = new DodgeballConfig(
      $options['apiUrl'] ?? null,
      $options['apiVersion'] ?? null,
      $options['isEnabled'] ?? true
    );
    $this->secretKey = $secretKey;
    $this->config = $config;
  }
  
  public function constructApiUrl(string $endpoint = ''): string {
    return $this->config->apiUrl . $this->config->apiVersion->value . '/' . $endpoint;
  }

  public function constructApiHeaders(?string $verificationId = "", ?string $sourceToken = "", ?string $customerId = "", string $sessionId = "") {
    $headers = [
      "Dodgeball-Secret-Key" => $this->secretKey,
      "Content-Type" => "application/json",
    ];

    if ($verificationId && $verificationId !== "null" && $verificationId !== "undefined") {
      $headers["Dodgeball-Verification-Id"] = $verificationId;
    }

    if ($sourceToken && $sourceToken !== "null" && $sourceToken !== "undefined") {
      $headers["Dodgeball-Source-Token"] = $sourceToken;
    }

    if ($customerId && $customerId !== "null" && $customerId !== "undefined") {
      $headers["Dodgeball-Customer-Id"] = $customerId;
    }

    if ($sessionId && $sessionId !== "null" && $sessionId !== "undefined") {
      $headers["Dodgeball-Session-Id"] = $sessionId;
    }

    return $headers;
  }

  public function createErrorResponse(int $code = 500, string $message = "Unknown evaluation error"): DodgeballCheckpointResponse {
    return new DodgeballCheckpointResponse(
      false,
      [
        (object) [
          'code' => $code,
          'message' => $message,
        ],
      ],
      DodgeballApiVersion::v1,
      new DodgeballVerification(
        '',
        VerificationStatus::FAILED,
        VerificationOutcome::ERROR
      )
    );
  }

  public function setHandlerStack(\GuzzleHttp\HandlerStack $handlerStack): void {
    $this->handlerStack = $handlerStack;
  }
  
  public function event(array $params = []): void {
    $event = new TrackEvent();

    if (isset($params['event'])) {
      $event = new TrackEvent(
        $params['event']->type ?? '',
        $params['event']->data ?? (object) [],
        $params['event']->eventTime ?? 0
      );
    }

    $eventParams = new EventParams(
      $params['userId'] ?? '',
      $params['sessionId'] ?? '',
      $params['sourceToken'] ?? '',
      $event
    );
    
    $sourceToken = $eventParams->sourceToken;
    $userId = $eventParams->userId;
    $sessionId = $eventParams->sessionId;

    if (!$this->config->isEnabled) {
      return;
    }

    // Construct a new Guzzle client and make a POST request to the Dodgeball API
    $client = new \GuzzleHttp\Client($this->handlerStack ? [ 'handler' => $this->handlerStack ] : []);
    $response = $client->post($this->constructApiUrl('track'), [
      'headers' => $this->constructApiHeaders(
        '',
        $sourceToken,
        $userId,
        $sessionId
      ),
      \GuzzleHttp\RequestOptions::JSON => [
        'type' => $event->type,
        'eventTime' => $event->eventTime,
        'data' => $event->data,
      ],
    ]);

    return;
  }

  // public function checkpoint(CheckpointParams $params): DodgeballCheckpointResponse {
  public function checkpoint(array $params = []): DodgeballCheckpointResponse {
    $checkpointName = $params['checkpointName'];

    $event = new CheckpointEvent();
    if (isset($params['event'])) {
      $event = new CheckpointEvent(
        $params['event']->ip ?? '',
        $params['event']->data ?? (object) []
      );
    }

    $sourceToken = $params['sourceToken'];
    $sessionId = $params['sessionId'];
    $userId = $params['userId'];
    $useVerificationId = $params['useVerificationId'];

    $options = new CheckpointResponseOptions();
    if (isset($params['options'])) {
      $options = new CheckpointResponseOptions(
        $params['options']->sync ?? true,
        $params['options']->timeout ?? 0,
        $params['options']->webhook ?? ''
      );
    }

    $trivialTimeout = !$options->timeout || $options->timeout <= 0;
    $largeTimeout = $options->timeout && $options->timeout > 5 * BASE_CHECKPOINT_TIMEOUT_MS;
    $mustPoll = $trivialTimeout || $largeTimeout;
    $activeTimeout = $mustPoll ? BASE_CHECKPOINT_TIMEOUT_MS : $options->timeout ?? BASE_CHECKPOINT_TIMEOUT_MS;

    $maximalTimeout = MAX_TIMEOUT;

    $internalOptions = (object) [
      'sync' => $options->sync === null || !isset($options->sync) ? true : $options->sync,
      'timeout' => $activeTimeout,
      'webhook' => $options->webhook ?? '',
    ];

    $response = null;
    $responseJson = null;
    $numRepeats = 0;
    $numFailures = 0;

    // Validate required parameters are present
    if ($checkpointName == null) {
      throw new DodgeballMissingParameterError(
        "checkpointName",
        $checkpointName
      );
    }

    if ($event == null) {
      throw new DodgeballMissingParameterError("event", $event);
    } else if (!property_exists($event, "ip")) {
      throw new DodgeballMissingParameterError("event.ip", $event->ip);
    }

    if ($sessionId == null) {
      throw new DodgeballMissingParameterError("sessionId", $sessionId);
    }

    if (!$this->config->isEnabled) {
      // Return a default verification response to allow for development without making requests
      return new DodgeballCheckpointResponse(
        true,
        [],
        DodgeballApiVersion::v1,
        new DodgeballVerification(
          "DODGEBALL_IS_DISABLED",
          VerificationStatus::COMPLETE,
          VerificationOutcome::APPROVED
        )
      );
    }

    // Convert timeout from milliseconds to seconds
    $timeout = $internalOptions->timeout / 1000 ?? 0;
    $client = new \GuzzleHttp\Client($this->handlerStack ? [ 'handler' => $this->handlerStack ] : []);

    while ((is_null($responseJson) || !$responseJson->success) && $numRepeats < 3) {
      // Construct a new Guzzle client and make a POST request to the Dodgeball API
      $response = $client->post($this->constructApiUrl('checkpoint'), [
        'headers' => $this->constructApiHeaders(
          $useVerificationId,
          $sourceToken,
          $userId,
          $sessionId
        ),
        \GuzzleHttp\RequestOptions::JSON => [
          'checkpointName' => $checkpointName,
          'event' => [
            'type' => $checkpointName,
            'ip' => $event->ip,
            'data' => $event->data,
          ],
          'options' => $internalOptions,
        ],
        \GuzzleHttp\RequestOptions::TIMEOUT => $timeout
      ]);

      if ($response) {
        $responseBody = $response->getBody();
        $responseJson = json_decode($responseBody);
      }

      $numRepeats += 1;
    }

    if ($response == null) {
      return $this->createErrorResponse();
    } else if ($response->getStatusCode() !== 200) {
      return $this->createErrorResponse($response->getStatusCode(), $response->getReasonPhrase());
    }

    $responseBody = $response->getBody();
    $responseJson = json_decode($responseBody);

    if (!$responseJson->success) {
      return new DodgeballCheckpointResponse(
        false,
        $responseJson->errors ?? [],
        DodgeballApiVersion::from($responseJson->version),
        new DodgeballVerification(
          $responseJson->verification->id ?? "",
          !is_null($responseJson->verification) && $responseJson->verification->status ? VerificationStatus::from($responseJson->verification->status) : VerificationStatus::FAILED,
          !is_null($responseJson->verification) && $responseJson->verification->outcome ? VerificationOutcome::from($responseJson->verification->outcome) : VerificationOutcome::ERROR
        ),
        false
      );
    }

    $status = $responseJson->verification->status ?? "";
    $outcome = $responseJson->verification->outcome ?? "";
    $isResolved = $status !== VerificationStatus::PENDING->value;
    $verificationId = $responseJson->verification->id ?? "";

    $numFailures = 0;
    $numRepeats = 0;
    $lastErrors = [];

    while (
      ($trivialTimeout ||
        ($options->timeout ?? BASE_CHECKPOINT_TIMEOUT_MS) >
          $numRepeats * $activeTimeout) &&
      !$isResolved &&
      $numFailures < MAX_RETRY_COUNT
    ) {
      if ($activeTimeout >= 1000) {
        $secondsToSleep = floor($activeTimeout / 1000);
        $microsecondsToSleep = ($activeTimeout - ($secondsToSleep * 1000)) * 1000;
        sleep($secondsToSleep);
        usleep($microsecondsToSleep);
      } else {
        usleep($activeTimeout * 1000);
      }

      $activeTimeout =
        $activeTimeout < $maximalTimeout ? 2 * $activeTimeout : $activeTimeout;

      $response = $client->get($this->constructApiUrl('verification/' . $verificationId), [
        'headers' => $this->constructApiHeaders(
          $useVerificationId,
          $sourceToken,
          $userId,
          $sessionId
        ),
      ]);

      if ($response) {
        $responseBody = $response->getBody();
        $responseJson = json_decode($responseBody);

        if ($responseJson->success) {
          $status = $responseJson->verification->status ?? "";
          
          if ($status) {
            $isResolved = $status !== VerificationStatus::PENDING->value;
            $numRepeats += 1;
          } else {
            $numFailures += 1;
          }
        } else {
          $lastErrors = $responseJson->errors ?? [];
          $numFailures += 1;
        }
      } else {
        $numFailures += 1;
      }
    }

    if ($numFailures >= MAX_RETRY_COUNT) {
      if (count($lastErrors) > 0) {
        return new DodgeballCheckpointResponse(
          false,
          $lastErrors,
          DodgeballApiVersion::v1,
          new DodgeballVerification(
            $verificationId,
            VerificationStatus::FAILED,
            VerificationOutcome::ERROR
          ),
          false
        );
      } else {
        $timeoutResponse = new DodgeballCheckpointResponse(
          false,
          [
            (object) [
              'code' => 503,
              'message' => "Service Unavailable: Maximum retry count exceeded",
            ],
          ],
          DodgeballApiVersion::v1,
          new DodgeballVerification(
            $verificationId,
            VerificationStatus::FAILED,
            VerificationOutcome::ERROR
          ),
          true
        );

        return $timeoutResponse;
      }
    }

    return new DodgeballCheckpointResponse(
      true,
      [],
      DodgeballApiVersion::v1,
      new DodgeballVerification(
        $responseJson->verification->id ?? "",
        !is_null($responseJson->verification) && $responseJson->verification->status ? VerificationStatus::from($responseJson->verification->status) : VerificationStatus::FAILED,
        !is_null($responseJson->verification) && $responseJson->verification->outcome ? VerificationOutcome::from($responseJson->verification->outcome) : VerificationOutcome::ERROR
      ),
      false
    );
  }
  
}

