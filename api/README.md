# FSL Service API

A secure API for external integrations with the FSL system, specifically designed for creating and managing reviewers.

## Configuration

Add the following to your `config.php`:

```php
$config['service_api'] = [
    'enabled' => true,
    'token' => 'fsl_api_7x9k2m4n8p3q6r9s2t5v8w1y4z7a0c3f6h9j2m5p8s1v4y7', // Secure API token for external integrations
    'allowed_ips' => [], // Optional IP whitelist - leave empty for no restrictions
    'rate_limit' => 100, // Requests per hour per IP
    'log_requests' => true,
    'reviewer_default_weight' => 0.50,
    'base_url' => 'http://localhost/psistorm.com/fsl' // Base URL for generating full URLs
];
```

## Authentication

All API requests require a token passed as a URL parameter:

```
POST /api/service.php?token=fsl_api_7x9k2m4n8p3q6r9s2t5v8w1y4z7a0c3f6h9j2m5p8s1v4y7
```

## Endpoints

### Create Reviewer

Creates a new reviewer with the specified settings. If a reviewer with the same name already exists, returns the existing reviewer's information instead of creating a duplicate.

**Request:**
```json
{
    "action": "create_reviewer",
    "data": {
        "name": "TwitchViewer123",
        "weight": 1.0,
        "notes": "From Twitch chat integration"
    }
}
```

**Response:**
```json
{
    "success": true,
    "action": "create_reviewer",
    "data": {
        "reviewer_id": 123,
        "name": "TwitchViewer123",
        "unique_url": "ea84fd14549a0d0d79120fbbc446b5bc",
        "full_url": "http://localhost/psistorm.com/fsl/score_match.php?token=ea84fd14549a0d0d79120fbbc446b5bc",
        "weight": "1.00",
        "status": "active",
        "created_at": "2024-01-15 10:30:00"
    },
    "message": "Reviewer created successfully"
}
```

### Get Reviewer Status

Checks if a user is already a reviewer.

**Request:**
```json
{
    "action": "get_reviewer_status",
    "data": {
        "name": "TwitchViewer123"
    }
}
```

**Response:**
```json
{
    "success": true,
    "action": "get_reviewer_status",
    "data": {
        "exists": true,
        "reviewer_id": 123,
        "name": "TwitchViewer123",
        "unique_url": "ea84fd14549a0d0d79120fbbc446b5bc",
        "weight": "1.00",
        "status": "active",
        "created_at": "2024-01-15 10:30:00"
    },
    "message": "Reviewer found"
}
```

### Update Reviewer Weight

Updates a reviewer's weight.

**Request:**
```json
{
    "action": "update_reviewer_weight",
    "data": {
        "reviewer_id": 123,
        "weight": 1.5
    }
}
```

**Response:**
```json
{
    "success": true,
    "action": "update_reviewer_weight",
    "data": {
        "reviewer_id": 123,
        "name": "TwitchViewer123",
        "weight": "1.50",
        "updated_at": "2024-01-15 10:35:00"
    },
    "message": "Reviewer weight updated successfully"
}
```

### Deactivate Reviewer

Deactivates a reviewer (soft delete).

**Request:**
```json
{
    "action": "deactivate_reviewer",
    "data": {
        "reviewer_id": 123
    }
}
```

**Response:**
```json
{
    "success": true,
    "action": "deactivate_reviewer",
    "data": {
        "reviewer_id": 123,
        "name": "TwitchViewer123",
        "status": "inactive",
        "updated_at": "2024-01-15 10:40:00"
    },
    "message": "Reviewer deactivated successfully"
}
```

## Error Responses

All endpoints return standardized error responses:

```json
{
    "success": false,
    "error": "error_type",
    "message": "Human readable error message",
    "code": 400
}
```

Common error types:
- `missing_token` - Authentication token is required
- `invalid_token` - Invalid authentication token
- `validation_error` - Input validation failed
- `reviewer_not_found` - Reviewer not found
- `database_error` - Internal database error

## Usage Examples

### cURL Example

```bash
curl -X POST 'http://localhost/psistorm.com/fsl/api/service.php?token=fsl_api_7x9k2m4n8p3q6r9s2t5v8w1y4z7a0c3f6h9j2m5p8s1v4y7' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "create_reviewer",
    "data": {
      "name": "TwitchViewer123",
      "weight": 1.0
    }
  }'
```

### PHP Example

```php
$data = [
    'action' => 'create_reviewer',
    'data' => [
        'name' => 'TwitchViewer123',
        'weight' => 1.0
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/psistorm.com/fsl/api/service.php?token=fsl_api_7x9k2m4n8p3q6r9s2t5v8w1y4z7a0c3f6h9j2m5p8s1v4y7');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
print_r($result);
```

### Twitch Integration Example

For Twitch chat bot integration, you can create a simple URL that users can click:

```
http://localhost/psistorm.com/fsl/api/service.php?token=fsl_api_7x9k2m4n8p3q6r9s2t5v8w1y4z7a0c3f6h9j2m5p8s1v4y7
```

The chat bot can send this URL via DM to viewers who want to become reviewers.

## Security Features

- **Token Authentication**: All requests require a valid token
- **Rate Limiting**: Configurable rate limiting per IP address
- **IP Whitelisting**: Optional IP address restrictions
- **Request Logging**: All API requests are logged for audit purposes
- **Input Validation**: All input is validated and sanitized
- **SQL Injection Prevention**: Uses prepared statements

## Database Tables

The API automatically creates the following tables if they don't exist:

- `api_rate_limit` - For rate limiting
- `api_request_log` - For request logging

## Testing

Use the included `test_api.php` file to test the API functionality:

```
http://localhost/psistorm.com/fsl/api/test_api.php
```

Make sure to update the token in the test file before running it.

## Rate Limiting

By default, the API allows 100 requests per hour per IP address. This can be configured in the `config.php` file.

## Logging

All API requests are logged to the `api_request_log` table for audit purposes. This includes:
- Request method and URI
- IP address
- Request body (truncated to 1000 characters)
- Response body (truncated to 1000 characters)
- HTTP status code
- User agent
- Timestamps

## Future Extensions

The API is designed to be easily extensible. To add new actions:

1. Create a new action class in `api/actions/`
2. Add the action to the switch statement in `api/service.php`
3. Update this documentation

The modular design makes it easy to add new functionality without affecting existing endpoints. 