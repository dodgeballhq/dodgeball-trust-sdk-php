# Dodgeball Server Trust SDK for PHP

## Table of Contents
- [Purpose](#purpose)
- [Prerequisites](#prerequisites)
- [Related](#related)
- [Installation](#installation)
- [Usage](#usage)
- [API](#api)
- [Testing](#testing)

## Purpose
[Dodgeball](https://dodgeballhq.com) enables developers to decouple security logic from their application code. This has several benefits including:
- The ability to toggle and compare security services like fraud engines, MFA, KYC, and bot prevention.
- Faster responses to new attacks. When threats evolve and new vulnerabilities are identified, your application's security logic can be updated without changing a single line of code.
- The ability to put in placeholders for future security improvements while focussing on product development.
- A way to visualize all application security logic in one place.

The Dodgeball Server Trust SDK for PHP makes integration with the Dodgeball API easy and is maintained by the Dodgeball team.

## Prerequisites
You will need to obtain an API key for your application from the [Dodgeball developer center](https://app.dodgeballhq.com/developer).

## Related
Check out the [Dodgeball Trust Client SDK](https://npmjs.com/package/@dodgeball/trust-sdk-client) for how to integrate Dodgeball into your frontend applications.

## Installation
Use `composer` to install the Dodgeball module:
```sh
composer require dodgeball/dodgeball-sdk-server
```

## Usage

```php
<?php
use Dodgeball\DodgeballSdkServer\Dodgeball;

$dodgeball = new Dodgeball('secret-api-key...');

$checkpointResponse = $dodgeball->checkpoint([
  'checkpointName' => 'PLACE_ORDER',
  'event' => (object) [
    'ip' => $_SERVER['REMOTE_ADDR'], // Make sure this is the real IP address of the client
    'data' => (object) [
      'key' => 'value',
      'nested' => (object) [
        'key' => 'nestedValue',
      ],
    ],
  ],
  'sourceToken' => $_SERVER['HTTP_X_DODGEBALL_SOURCE_TOKEN'],
  'sessionId' => $currentSessionId,
  'userId' => $currentUserId,
  'useVerificationId' => $_SERVER['HTTP_X_DODGEBALL_VERIFICATION_ID']
])
```

## API
### Configuration
___
The package requires a secret API key as the first argument to the constructor.
```php
const dodgeball = new Dodgeball("secret-api-key...");
```
Optionally, you can pass in several configuration options to the constructor:
```php
const dodgeball = new Dodgeball("secret-api-key...", {
  // Optional configuration (defaults shown here)
  'apiVersion' => "v1",
  'apiUrl' => "https://api.dodgeballhq.com",
  'isEnabled' => true
});
```
| Option | Default | Description |
|:-- |:-- |:-- |
| `apiVersion` | `v1` | The Dodgeball API version to use. |
| `apiUrl` | `https://api.dodgeballhq.com` | The base URL of the Dodgeball API. Useful for sending requests to different environments such as `https://api.sandbox.dodgeballhq.com`. |
| `isEnabled` | `true` | Whether or not to bypass actual calls to Dodgeball. Useful for local development to prevent invoking checkpoints and prevent tracked events from being submitted. |

### Call a Checkpoint
___
Checkpoints represent key moments of risk in an application and at the core of how Dodgeball works. A checkpoint can represent any activity deemed to be a risk. Some common examples include: login, placing an order, redeeming a coupon, posting a review, changing bank account information, making a donation, transferring funds, creating a listing.

```php
const $checkpointResponse = $dodgeball->checkpoint({
  'checkpointName': "CHECKPOINT_NAME",
  'event' => (object) [
    'ip' => $_SERVER['REMOTE_ADDR'], // Make sure this is the real IP address of the client
    'data' => (object) [
      'transaction' => (object) [
        'amount' => 100,
        'currency' => 'USD',
      ],
      'paymentMethod' => (object) [
        'token' => 'ghi789'
      ]
    ],
  ],
  'sourceToken' => 'abc123...', // Obtained from the Dodgeball Client SDK, represents the device making the request
  'sessionId' => 'session_def456', // The current session ID of the request
  'userId' => 'user_12345', // When you know the ID representing the user making the request in your database (ie after registration), pass it in here. Otherwise leave it blank.
  'useVerificationId' => 'def456' // Optional, if you have a verification ID, you can pass it in here
});
```
| Parameter | Required | Description |
|:-- |:-- |:-- |
| `checkpointName` | `true` | The name of the checkpoint to call. |
| `event` | `true` | The event to send to the checkpoint. |
| `event.ip` | `true` | The IP address of the device where the request originated. |
| `event.data` | `false` | Object containing arbitrary data to send in to the checkpoint. |
| `sourceToken` | `false` | A Dodgeball generated token representing the device making the request. Obtained from the [Dodgeball Trust Client SDK](https://npmjs.com/package/@dodgeball/trust-sdk-client). |
| `sessionId` | `true` | The current session ID of the request. |
| `userId` | `false` | When you know the ID representing the user making the request in your database (ie after registration), pass it in here. Otherwise leave it blank. |
| `useVerificationId` | `false` | If a previous verification was performed on this request, pass it in here. See the [useVerification](#useverification) section below for more details. |

### Interpreting the Checkpoint Response
___
Calling a checkpoint creates a verification in Dodgeball. The status and outcome of a verification determine how your application should proceed. Continue to [possible checkpoint responses](#possible-checkpoint-responses) for a full explanation of the possible status and outcome combinations and how to interpret them.
```php
$checkpointResponse = [
  'success' => boolean,
  'errors' => [
    [
      'code' => int,
      'message' => string
    ]
  ],
  'version' => string,
  'verification': [
    'id': string,
    'status': string,
    'outcome': string
  ]
];
```
| Property | Description |
|:-- |:-- |
| `success` | Whether the request encountered any errors was successful or failed. |
| `errors` | If the `success` flag is `false`, this will contain an array of error objects each with a `code` and `message`. |
| `version` | The version of the Dodgeball API that was used to make the request. Default is `v1`. |
| `verification` | Object representing the verification that was performed when this checkpoint was called. |
| `verification.id` | The ID of the verification that was created. |
| `verification.status` | The current status of the verification. See [Verification Statuses](#verification-statuses) for possible values and descriptions. |
| `verification.outcome` | The outcome of the verification. See [Verification Outcomes](#verification-outcomes) for possible values and descriptions. |

#### Verification Statuses
| Status | Description |
|:-- |:-- |
| `COMPLETE` | The verification was completed successfully. |
| `PENDING` | The verification is currently processing. |
| `BLOCKED` | The verification is waiting for input from the user. |
| `FAILED` | The verification encountered an error and was unable to proceed. |

#### Verification Outcomes
| Outcome | Description |
|:-- |:-- |
| `APPROVED` | The request should be allowed to proceed. |
| `DENIED` | The request should be denied. |
| `PENDING` | A determination on how to proceed has not been reached yet. |
| `ERROR` | The verification encountered an error and was unable to make a determination on how to proceed. |

#### Possible Checkpoint Responses

##### Approved
```php
$checkpointResponse = (object) [
  'success' => true,
  'errors' => [],
  'version' => 'v1',
  'verification' => (object) [
    'id' => 'def456',
    'status' => 'COMPLETE',
    'outcome' => 'APPROVED',
  ],
];
```
When a request is allowed to proceed, the verification `status` will be `COMPLETE` and `outcome` will be `APPROVED`.

##### Denied
```php
$checkpointResponse = (object) [
  'success' => true,
  'errors' => [],
  'version' => 'v1',
  'verification' => (object) [
    'id' => 'def456',
    'status' => 'COMPLETE',
    'outcome' => 'DENIED',
  ],
];
```
When a request is denied, verification `status` will be `COMPLETE` and `outcome` will be `DENIED`.

##### Pending
```php
$checkpointResponse = (object) [
  'success' => true,
  'errors' => [],
  'version' => 'v1',
  'verification' => (object) [
    'id' => 'def456',
    'status' => 'PENDING',
    'outcome' => 'PENDING',
  ],
];
```
If the verification is still processing, the `status` will be `PENDING` and `outcome` will be `PENDING`.

##### Blocked
```php
$checkpointResponse = (object) [
  'success' => true,
  'errors' => [],
  'version' => 'v1',
  'verification' => (object) [
    'id' => 'def456',
    'status' => 'BLOCKED',
    'outcome' => 'PENDING',
  ],
];
```
A blocked verification requires additional input from the user before proceeding. When a request is blocked, verification `status` will be `BLOCKED` and the `outcome` will be `PENDING`.

##### Undecided
```php
$checkpointResponse = (object) [
  'success' => true,
  'errors' => [],
  'version' => 'v1',
  'verification' => (object) [
    'id' => 'def456',
    'status' => 'COMPLETE',
    'outcome' => 'PENDING',
  ],
];
```
If the verification has finished, with no determination made on how to proceed, the verification `status` will be `COMPLETE` and the `outcome` will be `PENDING`.

##### Error
```php
$checkpointResponse = (object) [
  'success' => false,
  'errors' => [
    (object) [
      'code' => 503,
      'message' => '[Service Name]: Service is unavailable',
    ],
  ],
  'version' => 'v1',
  'verification' => (object) [
    'id' => 'def456',
    'status' => 'FAILED',
    'outcome' => 'ERROR',
  ],
];
```
If a verification encounters an error while processing (such as when a 3rd-party service is unavailable), the `success` flag will be false. The verification `status` will be `FAILED` and the `outcome` will be `ERROR`. The `errors` array will contain at least one object with a `code` and `message` describing the error(s) that occurred.

### Utility Methods
___
There are several utility methods available to help interpret the checkpoint response. It is strongly advised to use them rather than directly interpreting the checkpoint response.

#### `checkpointResponse->isAllowed()`
The `isAllowed` method returns `true` if the request is allowed to proceed.

#### `checkpointResponse->isDenied()`
The `isDenied` method returns `true` if the request is denied and should not be allowed to proceed.

#### `checkpointResponse->isRunning()`
The `isRunning` method returns `true` if no determination has been reached on how to proceed. The verification should be returned to the frontend application to gather additional input from the user. See the [useVerification](#useverification) section for more details on use and an end-to-end example.

#### `checkpointResponse->isUndecided()`
The `isUndecided` method returns `true` if the verification has finished and no determination has been reached on how to proceed. See [undecided](#undecided) for more details.

#### `checkpointResponse->hasError()`
The `hasError` method returns `true` if it contains an error.

#### `checkpointResponse->isTimeout()`
The `isTimeout` method returns `true` if the verification has timed out. At which point it is up to the application to decide how to proceed. 

### useVerification
___
Sometimes additional input is required from the user before making a determination about how to proceed. For example, if a user should be required to perform 2FA before being allowed to proceed, the checkpoint response will contain a verification with `status` of `BLOCKED` and  outcome of `PENDING`. In this scenario, you will want to return the verification to your frontend application. Inside your frontend application, you can pass the returned verification directly to the `dodgeball.handleVerification()` method to automatically handle gathering additional input from the user. Continuing with our 2FA example, the user would be prompted to select a phone number and enter a code sent to that number. Once the additional input is received, the frontend application should simply send along the ID of the verification performed to your API. Passing that verification ID to the `useVerification` option will allow that verification to be used for this checkpoint instead of creating a new one. This prevents duplicate verifications being performed on the user. 

**Important Note:** To prevent replay attacks, each verification ID can only be passed to `useVerification` once.

### Track an Event
___
You can track additional information about a user's journey by submitting tracking events from your server. This information will be added to the user's profile and is made available to checkpoints.

```php
$dodgeball->event([
  'event' => [
    'type' => 'EVENT_NAME', // Can be any string you choose
    'data' => [
      // Arbitrary data to track...
      'transaction' => [
        'amount' => 100,
        'currency' => 'USD',
      ],
      'paymentMethod' => [
        'token' => 'ghi789',
      ],
    ],
  ],
  'sourceToken' => 'abc123...', // Obtained from the Dodgeball Client SDK, represents the device making the request
  'sessionId' => 'session_def456', // The current session ID of the request
  'userId' => 'user_12345', // When you know the ID representing the user making the request in your database (ie after registration), pass it in here. Otherwise leave it blank.
]);
```
| Parameter | Required | Description |
|:-- |:-- |:-- |
| `event` | `true` | The event to track. |
| `event.type` | `true` | A name representing where in the journey the user is. |
| `event.data` | `false` | Object containing arbitrary data to track. |
| `sourceToken` | `false` | A Dodgeball generated token representing the device making the request. Obtained from the [Dodgeball Trust Client SDK](https://npmjs.com/package/@dodgeball/trust-sdk-client). |
| `sessionId` | `true` | The current session ID of the request. |
| `userId` | `false` | When you know the ID representing the user making the request in your database (ie after registration), pass it in here. Otherwise leave it blank. |

#### End-to-End Example
```js
// In your frontend application...
const placeOrder = async (order, previousVerificationId = null) => {
  const sourceToken = await dodgeball.getSourceToken();

  const endpointResponse = await axios.post("/api/orders", { order }, {
    headers: {
      "x-dodgeball-source-token": sourceToken, // Pass the source token to your API
      "x-dodgeball-verification-id": previousVerificationId // If a previous verification was performed, pass it along to your API
    }
  });

  dodgeball.handleVerification(endpointResponse.data.verification, {
    onVerified: async (verification) => {
      // If an additional check was performed and the request is approved, simply pass the verification ID in to your API
      await placeOrder(order, verification.id);
    },
    onApproved: async () => {
      // If no additional check was required, update the view to show that the order was placed
      setIsOrderPlaced(true);
    },
    onDenied: async (verification) => {
      // If the action was denied, update the view to show the rejection
      setIsOrderDenied(true);
    },
    onError: async (error) => {
      // If there was an error performing the verification, display it
      setError(error);
      setIsPlacingOrder(false);
    }
  });
}
```

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// In your API...
Route::post('/api/orders', function(Request $request) {
  // In moments of risk, call a checkpoint within Dodgeball to verify the request is allowed to proceed
  $order = $request->input('order');
  $checkpointResponse = $dodgeball->checkpoint([
    'checkpointName' => 'PLACE_ORDER',
    'event' => [
      'ip' => $request->ip(),
      'data' => [
        'order' => $order,
      ],
    ],
    'sourceToken' => $request->header('x-dodgeball-source-token'),
    'sessionId' => $request->session()->getId(),
    'userId' => $request->session()->get('userId'),
    'useVerificationId' => $request->header('x-dodgeball-verification-id'),
  ]);

  if ($checkpointResponse->isAllowed()) {
    // Proceed with placing the order...
    $placedOrder = app('database')->createOrder($order);
    return response()->json([
      'order' => $placedOrder,
    ]);
  } else if ($checkpointResponse->isRunning()) {
    // If the outcome is pending, send the verification to the frontend to do additional checks (such as MFA, KYC)
    return response()->json([
      'verification' => $checkpointResponse->verification,
    ], 202);
  } else if ($checkpointResponse->isDenied()) {
    // If the request is denied, you can return the verification to the frontend to display a reason message
    return response()->json([
      'verification' => $checkpointResponse->verification,
    ], 403);
  } else {
    // If the checkpoint failed, decide how you would like to proceed. You can return the error, choose to proceed, retry, or reject the request.
    return response()->json([
      'message' => $checkpointResponse->errors,
    ], 500);
  }
});
```

## Running Tests

This package uses PHPUnit for testing. To run the tests, run the following command from the root of the project:
```sh
./vendor/bin/phpunit --display-warnings --display-deprecations tests
```