<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_env.php';

if (!function_exists('cors_allowed_origins')) {
    function cors_collect_origin_candidates(array $values): array
    {
        $origins = [];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $parts = array_map('trim', explode(',', $value));
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                $parsed = parse_url($part);
                if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
                    continue;
                }

                $origin = strtolower((string) $parsed['scheme']) . '://' . strtolower((string) $parsed['host']);
                if (isset($parsed['port'])) {
                    $origin .= ':' . (int) $parsed['port'];
                }

                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    function cors_allowed_origins(): array
    {
        $configured = cors_collect_origin_candidates([
            backend_env('CORS_ALLOWED_ORIGINS'),
            backend_env('GOOGLE_OAUTH_SUCCESS_URL'),
            backend_env('GOOGLE_OAUTH_SUCCESS_URI'),
            backend_env('PUBLIC_BASE_URL'),
            backend_env('APP_PUBLIC_BASE_URL'),
            backend_env('FRONTEND_URL'),
            backend_env('FRONTEND_BASE_URL'),
        ]);

        // Safe defaults for local development and the current Render deployment.
        $defaults = [
            'http://localhost',
            'http://127.0.0.1',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'https://scholar-frontend-yqnn.onrender.com',
            'https://scholar-backend-1tso.onrender.com',
        ];

        return array_values(array_unique(array_merge($configured, $defaults)));
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

        if ($originScheme === 'https' && str_ends_with($originHost, '.onrender.com')) {
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
//testing