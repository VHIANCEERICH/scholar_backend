<?php
declare(strict_types=1);

if (!function_exists('cors_allowed_origins')) {
    function cors_allowed_origins(): array
    {
        $configured = trim((string) (getenv('CORS_ALLOWED_ORIGINS') ?: ''));
        if ($configured !== '') {
            $parts = array_map('trim', explode(',', $configured));
            $parts = array_values(array_filter($parts, static fn (string $origin): bool => $origin !== ''));
            return array_unique($parts);
        }

        // Safe dev defaults; production should set CORS_ALLOWED_ORIGINS explicitly.
        return [
            'http://localhost',
            'http://127.0.0.1',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ];
    }
}

if (!function_exists('cors_origin_allowed')) {
    function cors_origin_allowed(string $origin, array $allowedOrigins): bool
    {
        if ($origin === '' || $allowedOrigins === []) {
            return false;
        }

        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        return in_array($origin, $allowedOrigins, true);
    }
}

if (!function_exists('apply_cors_headers')) {
    function apply_cors_headers(
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With']
    ): void {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $allowedOrigins = cors_allowed_origins();

        if (cors_origin_allowed($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));
    }
}

if (!function_exists('handle_preflight')) {
    function handle_preflight(int $statusCode = 204): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
            http_response_code($statusCode);
            exit;
        }
    }
}
