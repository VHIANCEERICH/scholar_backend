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

        $parsedOrigin = parse_url($origin);
        if (!is_array($parsedOrigin) || !isset($parsedOrigin['scheme'], $parsedOrigin['host'])) {
            return false;
        }

        $originScheme = strtolower((string) $parsedOrigin['scheme']);
        $originHost = strtolower((string) $parsedOrigin['host']);
        $originPort = isset($parsedOrigin['port']) ? (int) $parsedOrigin['port'] : null;

        // Allow localhost/127.0.0.1 on any port for local web development.
        if ($originScheme === 'http' && in_array($originHost, ['localhost', '127.0.0.1'], true)) {
            return true;
        }

        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        foreach ($allowedOrigins as $allowed) {
            $allowed = trim((string) $allowed);
            if ($allowed === '') {
                continue;
            }

            if ($allowed === $origin) {
                return true;
            }

            $parsedAllowed = parse_url($allowed);
            if (!is_array($parsedAllowed) || !isset($parsedAllowed['scheme'], $parsedAllowed['host'])) {
                continue;
            }

            $allowedScheme = strtolower((string) $parsedAllowed['scheme']);
            $allowedHost = strtolower((string) $parsedAllowed['host']);
            $allowedPort = isset($parsedAllowed['port']) ? (int) $parsedAllowed['port'] : null;

            $schemeMatches = $originScheme === $allowedScheme;
            $hostMatches = $originHost === $allowedHost;
            $portMatches = $allowedPort === null || $originPort === $allowedPort;

            if ($schemeMatches && $hostMatches && $portMatches) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('apply_cors_headers')) {
    function apply_cors_headers(
        array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With']
    ): void {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            $forwardedOrigin = trim((string) ($_SERVER['HTTP_X_FORWARDED_ORIGIN'] ?? ''));
            if ($forwardedOrigin !== '') {
                $origin = $forwardedOrigin;
            }
        }
        $allowedOrigins = cors_allowed_origins();

        $allowOrigin = '*';
        if ($origin !== '' && cors_origin_allowed($origin, $allowedOrigins)) {
            $allowOrigin = $origin;
        }
        header('Access-Control-Allow-Origin: ' . $allowOrigin);
        header('Vary: Origin');

        header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));
        header('Access-Control-Max-Age: 86400');
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
