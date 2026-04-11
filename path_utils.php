<?php
declare(strict_types=1);

if (!function_exists('normalize_upload_path')) {
    function normalize_upload_path(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', trim($path));
        if ($normalizedPath === '') {
            return '';
        }

        // If a full URL (or nested serve_file URL) is passed, extract the original path.
        if (preg_match('/^https?:\/\//i', $normalizedPath)) {
            $parts = parse_url($normalizedPath);
            if (is_array($parts)) {
                $query = [];
                parse_str((string) ($parts['query'] ?? ''), $query);
                $candidate = trim((string) ($query['path'] ?? ''));
                if ($candidate !== '') {
                    $normalizedPath = str_replace('\\', '/', $candidate);
                } else {
                    $normalizedPath = str_replace('\\', '/', (string) ($parts['path'] ?? ''));
                }
            }
        } elseif (stripos($normalizedPath, 'serve_file.php') !== false && str_contains($normalizedPath, 'path=')) {
            $queryPos = strpos($normalizedPath, '?');
            if ($queryPos !== false) {
                $query = [];
                parse_str((string) substr($normalizedPath, $queryPos + 1), $query);
                $candidate = trim((string) ($query['path'] ?? ''));
                if ($candidate !== '') {
                    $normalizedPath = str_replace('\\', '/', $candidate);
                }
            }
        }

        // Accept absolute filesystem paths by extracting the uploads segment.
        $uploadsPos = stripos($normalizedPath, '/uploads/');
        if ($uploadsPos !== false) {
            $normalizedPath = substr($normalizedPath, $uploadsPos + 1);
        } else {
            $normalizedPath = preg_replace('#^.*?/scholar_php/#i', '', $normalizedPath);
            $normalizedPath = ltrim((string) $normalizedPath, '/');

            // Fallback for values like "uploads/file.png" without a leading slash.
            if (!preg_match('#^uploads(?:/|$)#i', $normalizedPath)) {
                $uploadsPrefixPos = stripos($normalizedPath, 'uploads/');
                if ($uploadsPrefixPos !== false) {
                    $normalizedPath = substr($normalizedPath, $uploadsPrefixPos);
                }
            }
        }

        $normalizedPath = preg_replace('#/{2,}#', '/', $normalizedPath);
        return ltrim((string) $normalizedPath, '/');
    }
}
