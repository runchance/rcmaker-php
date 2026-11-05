<?php

namespace RC\Helper\Db\Laravel;

/** Resolve ordinary relative SQLite files against the external project root, preserving native memory/URI modes. */
class SQLiteConnector extends \Illuminate\Database\Connectors\SQLiteConnector
{
    /** Avoid the incompatible global base_path($path) fallback and never create a missing database silently. */
    protected function parseDatabasePath(string $path): string
    {
        if ($path === ':memory:' || str_starts_with($path, 'file:') || str_contains($path, '?mode=memory') || str_contains($path, '&mode=memory')) {
            return parent::parseDatabasePath($path);
        }
        $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path);
        $resolved = realpath($absolute ? $path : \phar_path() . '/' . $path);
        if ($resolved === false || !is_file($resolved)) {
            throw new \Illuminate\Database\SQLiteDatabaseDoesNotExistException($path);
        }
        return parent::parseDatabasePath($resolved);
    }
}
