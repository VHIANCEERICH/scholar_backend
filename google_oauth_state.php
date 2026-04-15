<?php
declare(strict_types=1);

function oauth_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function oauth_base64url_decode(string $data): ?string
{
    $normalized = strtr($data, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    return $decoded === false ? null : $decoded;
}

function oauth_state_secret(): string
{
    $stateSecret = trim((string) backend_env('GOOGLE_OAUTH_STATE_SECRET', ''));
    if ($stateSecret !== '') {
        return $stateSecret;
    }

    $clientId = trim((string) backend_env('GOOGLE_CLIENT_ID', backend_env('GOOGLE_OAUTH_CLIENT_ID', '')));
    $clientSecret = trim((string) backend_env('GOOGLE_CLIENT_SECRET', backend_env('GOOGLE_OAUTH_CLIENT_SECRET', '')));

    return hash('sha256', 'scholar-oauth-state|' . $clientId . '|' . $clientSecret);
}

function oauth_state_encode(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode OAuth state.');
    }

    $body = oauth_base64url_encode($json);
    $signature = hash_hmac('sha256', $body, oauth_state_secret(), true);

    return $body . '.' . oauth_base64url_encode($signature);
}

function oauth_state_decode(string $state): ?array
{
    $state = trim($state);
    if ($state === '') {
        return null;
    }

    $parts = explode('.', $state, 2);
    if (count($parts) !== 2) {
        return oauth_state_decode_payload($state);
    }

    [$body, $signature] = $parts;
    $bodyJson = oauth_base64url_decode($body);
    $signatureBytes = oauth_base64url_decode($signature);
    if ($bodyJson === null || $signatureBytes === null) {
        return oauth_state_decode_payload($body);
    }

    $expectedSignature = hash_hmac('sha256', $body, oauth_state_secret(), true);
    if (!hash_equals($expectedSignature, $signatureBytes)) {
        return oauth_state_decode_payload($body);
    }

    $payload = json_decode($bodyJson, true);
    if (!is_array($payload)) {
        return null;
    }

    return oauth_state_validate_payload($payload);
}

function oauth_state_decode_payload(string $encoded): ?array
{
    $json = oauth_base64url_decode($encoded);
    if ($json === null) {
        return null;
    }

    $payload = json_decode($json, true);
    return oauth_state_validate_payload($payload);
}

function oauth_state_validate_payload($payload): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    if (!isset($payload['role'])) {
        return null;
    }

    $issuedAt = (int) ($payload['ts'] ?? 0);
    if ($issuedAt <= 0) {
        return null;
    }

    return $payload;
}

function oauth_success_url(): string
{
    return trim((string) backend_env('GOOGLE_OAUTH_SUCCESS_URL', ''));
}
