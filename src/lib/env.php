<?php

declare(strict_types=1);

/** A trimmed environment variable, or null when unset or empty. */
function env(string $name): ?string
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }
    $value = trim($value);
    return $value === '' ? null : $value;
}

/** "local" for docker-compose development; anything else is treated as production. */
function app_env(): string
{
    return strtolower(env('APP_ENV') ?? 'production');
}

/** HTML-escape for output. */
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
