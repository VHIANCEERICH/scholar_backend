<?php
declare(strict_types=1);

function backend_env_file_path(): string
{
    return __DIR__ . '/.env';
}

function backend_env_load_file(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $cached = [];
    $path = backend_env_file_path();
    if (!is_file($path)) {
        return $cached;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $cached;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }

        if ($value !== '') {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === '\'' && $last === '\'')) {
                $value = substr($value, 1, -1);
            }
        }

        $cached[$key] = $value;
    }

    return $cached;
}

function backend_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value !== false && trim((string) $value) !== '') {
        return trim((string) $value);
    }

    $fileValues = backend_env_load_file();
    if (array_key_exists($name, $fileValues)) {
        return trim((string) $fileValues[$name]);
    }

    return $default;
}