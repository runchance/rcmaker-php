<?php

declare(strict_types=1);

namespace RC\Project;

/** Shared version gate for generated entries and project-management commands. */
final class Platform
{
    public const PHP_CONSTRAINT = '>=8.5.12';

    /** Check the real runtime, or an explicit version in a test; 8.5.12 is the minimum. */
    public static function check(string $version = PHP_VERSION): void
    {
        if (!version_compare($version, '8.5.12', '>=')) {
            throw new \RuntimeException('RCMAKER requires PHP >= 8.5.12; current version: ' . $version);
        }
    }
}
