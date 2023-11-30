<?php
namespace Dodgeball\DodgeballSdkServer;

use Exception;
use \Ramsey\Uuid;

const BASE_CHECKPOINT_TIMEOUT_MS = 1000;
const MAX_TIMEOUT = 10000;
const MAX_RETRY_COUNT = 3;

class DodgeballApiVersion {
  const v1 = 'v1';

  public static function fromString($version) {
    switch ($version) {
      case 'v1':
        return DodgeballApiVersion::v1;
      default:
        return DodgeballApiVersion::v1;
    }
  }
}

class VerificationStatus {
  const PENDING = 'PENDING';
  const BLOCKED = 'BLOCKED';
  const COMPLETE = 'COMPLETE';
  const FAILED = 'FAILED';

  public static function fromString($status) {
    switch ($status) {
      case 'PENDING':
        return VerificationStatus::PENDING;
      case 'BLOCKED':
        return VerificationStatus::BLOCKED;
      case 'COMPLETE':
        return VerificationStatus::COMPLETE;
      case 'FAILED':
        return VerificationStatus::FAILED;
      default:
        return VerificationStatus::FAILED;
    }
  }
}

class VerificationOutcome {
  const APPROVED = 'APPROVED';
  const DENIED = 'DENIED';
  const PENDING = 'PENDING';
  const ERROR = 'ERROR';

  public static function fromString($outcome) {
    switch ($outcome) {
      case 'APPROVED':
        return VerificationOutcome::APPROVED;
      case 'DENIED':
        return VerificationOutcome::DENIED;
      case 'PENDING':
        return VerificationOutcome::PENDING;
      case 'ERROR':
        return VerificationOutcome::ERROR;
      default:
        return VerificationOutcome::ERROR;
    }
  }
}

class DodgeballConfig
{
  public $apiUrl = 'https://api.dodgeballhq.com/';
  public $apiVersion = DodgeballApiVersion::v1;
  public $isEnabled = true;

  // All properties are optional parameters to the constructor
  function __construct(
    string $apiUrl = null,
    string $apiVersion = null,
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
  public $userId = '';
  public $sessionId = '';
  public $sourceToken = '';
  public $event;

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
  public $type = '';
  public $eventTime = 0;
  public $data;

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
  public $ip = '';
  public $data;

  function __construct(string $ip = '', object $data = null) {
    $this->ip = $ip;
    $this->data = $data ?? new \stdClass();
  }
}

class CheckpointResponseOptions
{
  public $sync = true;
  public $timeout = 0;
  public $webhook = '';

  function __construct(bool $sync = true, int $timeout = 0, string $webhook = '') {
    $this->sync = $sync;
    $this->timeout = $timeout;
    $this->webhook = $webhook;
  }
}

class CheckpointParams
{
  public $checkpointName = '';
  public $event;
  public $sourceToken = '';
  public $sessionId = '';
  public $userId = '';
  public $useVerificationId = '';
  public $options;

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
  public $id = '';
  public $status = VerificationStatus::PENDING;
  public $outcome = VerificationOutcome::PENDING;

  function __construct(string $id = '', string $status = VerificationStatus::PENDING, string $outcome = VerificationOutcome::PENDING) {
    $this->id = $id;
    $this->status = $status;
    $this->outcome = $outcome;
  }
}

class DodgeballCheckpointResponse
{
  public $success = false;
  public $errors = [];
  public $version = DodgeballApiVersion::v1;
  public $verification;
  public $isTimeout = false;

  function __construct(bool $success = false, array $errors = [], string $version = DodgeballApiVersion::v1, DodgeballVerification $verification = null, bool $isTimeout = false) {
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
  protected $secretKey;
  protected $config;
  protected $handlerStack = null;

  function __construct(string $secretKey, array $options = []) {
    if (!$secretKey) {
      throw new DodgeballMissingParameterError('secretKey', $secretKey);
    }

    $apiVersion = null;
    $optionsVersion = null;

    if (array_key_exists('apiVersion', $options)) {
      $optionsVersion = $options['apiVersion'];
    }

    if (property_exists('DodgeballApiVersion', $optionsVersion)) {
      $apiVersion = DodgeballApiVersion::$$optionsVersion;
    }

    // Default config if not provided
    $config = new DodgeballConfig(
      $options['apiUrl'] ?? null,
      $apiVersion,
      $options['isEnabled'] ?? true
    );
    $this->secretKey = $secretKey;
    $this->config = $config;
  }
  
  public function constructApiUrl(string $endpoint = ''): string {
    return $this->config->apiUrl . $this->config->apiVersion . '/' . $endpoint;
  }

  public function constructApiHeaders(?string $verificationId = "", ?string $sourceToken = "", ?string $customerId = "", string $sessionId = "", ?string $requestId = "") {
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

    if ($requestId && $requestId !== "null" && $requestId !== "undefined") {
      $headers["Dodgeball-Request-Id"] = $requestId;
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
        $params['event']['type'] ?? '',
        (object) ($params['event']['data'] ?? []),
        $params['event']['eventTime'] ?? 0
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

  public function checkpoint(array $params = []): DodgeballCheckpointResponse {
    $checkpointName = $params['checkpointName'];

    $event = new CheckpointEvent();
    if (isset($params['event'])) {
      $event = new CheckpointEvent(
        $params['event']['ip'] ?? '',
        (object) ($params['event']['data'] ?? [])
      );
    }

    $sourceToken = $params['sourceToken'];
    $sessionId = $params['sessionId'];
    $userId = $params['userId'];
    $useVerificationId = $params['useVerificationId'];

    $options = new CheckpointResponseOptions();
    if (isset($params['options'])) {
      $options = new CheckpointResponseOptions(
        is_null($params['options']['sync']) || !isset($params['options']['sync']) ? true : $params['options']['sync'],
        $params['options']['timeout'] ?? 0,
        $params['options']['webhook'] ?? ''
      );
    }

    $trivialTimeout = !$options->timeout || $options->timeout <= 0;
    $largeTimeout = $options->timeout && $options->timeout > 5 * BASE_CHECKPOINT_TIMEOUT_MS;
    $mustPoll = $trivialTimeout || $largeTimeout;
    $activeTimeout = $mustPoll ? BASE_CHECKPOINT_TIMEOUT_MS : $options->timeout ?? BASE_CHECKPOINT_TIMEOUT_MS;

    $maximalTimeout = MAX_TIMEOUT;

    $internalOptions = (object) [
      'sync' => is_null($options->sync) || !isset($options->sync) ? true : $options->sync,
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

    if ($sessionId == null && $sourceToken == null) {
      throw new DodgeballMissingParameterError("Must provide either a sessionId or sourceToken", "sessionId = " . $sessionId . ", sourceToken = " . $sourceToken);
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
    $lastErrors = [];
    $requestId = Uuid\Uuid::uuid4()->toString();

    while ((is_null($responseJson) || !$responseJson->success) && $numRepeats < 3) {
      try {
        // Construct a new Guzzle client and make a POST request to the Dodgeball API
        $response = $client->post($this->constructApiUrl('checkpoint'), [
          'headers' => $this->constructApiHeaders(
            $useVerificationId,
            $sourceToken,
            $userId,
            $sessionId,
            $requestId
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
      } catch (\Exception $e) {
        $lastErrors = [
          (object) [
            'code' => 503,
            'message' => $e->getMessage(),
          ],
        ];
      }

      $numRepeats += 1;
    }

    if (is_null($response)) {
      $lastErrorMessage = $lastErrors[0]->message ?? "Unknown evaluation error";
      // If the last error was a timeout, return a timeout response
      if (strpos($lastErrorMessage, 'timed out') !== false) {
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
            "DODGEBALL_TIMEOUT",
            VerificationStatus::FAILED,
            VerificationOutcome::ERROR
          ),
          true
        );

        return $timeoutResponse;
      } else {
        return $this->createErrorResponse($lastErrors[0]->code ?? 500, $lastErrors[0]->message ?? "Unknown evaluation error");
      }
    } else if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
      return $this->createErrorResponse($response->getStatusCode(), $response->getReasonPhrase());
    }

    $responseBody = $response->getBody();
    $responseJson = json_decode($responseBody);

    if (!$responseJson->success) {
      $responseVerificationStatus = !is_null($responseJson->verification) ? $responseJson->verification->status : VerificationStatus::FAILED;
      $responseVerificationOutcome = !is_null($responseJson->verification) ? $responseJson->verification->outcome : VerificationOutcome::ERROR;

      $responseVersion = !is_null($responseJson->version) ? $responseJson->version : DodgeballApiVersion::v1;

      return new DodgeballCheckpointResponse(
        false,
        $responseJson->errors ?? [],
        DodgeballApiVersion::fromString($responseVersion),
        new DodgeballVerification(
          $responseJson->verification->id ?? "",
          VerificationStatus::fromString($responseVerificationStatus),
          VerificationOutcome::fromString($responseVerificationOutcome)
        ),
        false
      );
    }

    $status = $responseJson->verification->status ?? "";
    $outcome = $responseJson->verification->outcome ?? "";
    $isResolved = $status !== VerificationStatus::PENDING;
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
      try {
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
              $isResolved = $status !== VerificationStatus::PENDING;
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
      } catch (\Exception $e) {
        $lastErrors = [
          (object) [
            'code' => 503,
            'message' => $e->getMessage(),
          ],
        ];
        $numFailures += 1;
      }
    }

    if ($numFailures >= MAX_RETRY_COUNT) {
      $lastErrorMessage = $lastErrors[0]->message ?? "Unknown evaluation error";

      if (count($lastErrors) > 0) {
        if (strpos($lastErrorMessage, 'timed out') !== false) {
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
        } else {
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
        }
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

    $responseVerificationStatus = !is_null($responseJson->verification) ? $responseJson->verification->status : VerificationStatus::FAILED;
    $responseVerificationOutcome = !is_null($responseJson->verification) ? $responseJson->verification->outcome : VerificationOutcome::ERROR;

    return new DodgeballCheckpointResponse(
      true,
      [],
      DodgeballApiVersion::v1,
      new DodgeballVerification(
        $responseJson->verification->id ?? "",
        VerificationStatus::fromString($responseVerificationStatus),
        VerificationOutcome::fromString($responseVerificationOutcome)
      ),
      false
    );
  }
  
}

