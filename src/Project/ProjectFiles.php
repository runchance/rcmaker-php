<?php

declare(strict_types=1);

namespace RC\Project;

/** Confined project writes; tools never follow links into another project. */
final class ProjectFiles
{
    public readonly string $root;

    /** Resolve an existing project directory, for example new ProjectFiles(getcwd()). */
    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \RuntimeException('Project directory does not exist: ' . $root);
        }
        $this->root = str_replace('\\', '/', $resolved);
        if ($this->root === str_replace('\\', '/', dirname(__DIR__, 2))) {
            throw new \RuntimeException('Do not initialize the framework source directory; select an application with --project.');
        }
    }

    /** Validate a project-relative file and reject symlinks, traversal and non-directory parents. */
    public function path(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, ':') || str_contains($relative, "\0")) {
            throw new \InvalidArgumentException('Invalid project-relative path.');
        }
        $segments = explode('/', $relative);
        $path = rtrim($this->root, '/');
        foreach ($segments as $index => $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \InvalidArgumentException('Invalid project-relative path: ' . $relative);
            }
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new \RuntimeException('Refusing linked project path: ' . $relative);
            }
            if ($index < count($segments) - 1 && file_exists($path) && !is_dir($path)) {
                throw new \RuntimeException('Project parent is not a directory: ' . $relative);
            }
        }
        return $path;
    }

    /** Read a regular file, or return null for a missing path; never evaluate project PHP. */
    public function read(string $relative): ?string
    {
        $path = $this->path($relative);
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path) || ($data = file_get_contents($path)) === false) {
            throw new \RuntimeException('Cannot read project file: ' . $relative);
        }
        return $data;
    }

    /** Apply preflighted changes and roll back owned writes if any step fails. */
    public function commit(array $changes): void
    {
        foreach ($changes as $relative => $change) {
            $this->assertExpected($relative, $change['before']);
        }
        $written = [];
        try {
            foreach ($changes as $relative => $change) {
                $this->write($relative, $change['after'], $change['before']);
                $written[$relative] = $change;
            }
        } catch (\Throwable $error) {
            foreach (array_reverse($written, true) as $relative => $change) {
                try {
                    $this->write($relative, $change['before'], $change['after']);
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('Rollback needs manual review at ' . $relative . ': ' . $rollbackError->getMessage(), 0, $error);
                }
            }
            throw $error;
        }
    }

    /** Detect edits made after preview, before touching any destination. */
    private function assertExpected(string $relative, ?string $expected): void
    {
        if ($this->read($relative) !== $expected) {
            throw new \RuntimeException('File changed since preview; rerun the command: ' . $relative);
        }
    }

    /** Create exclusively, or replace through a same-directory temporary file. */
    private function write(string $relative, ?string $contents, ?string $before): void
    {
        $this->assertExpected($relative, $before);
        $path = $this->path($relative);
        if ($contents === null) {
            if (!unlink($path)) {
                throw new \RuntimeException('Cannot roll back new file: ' . $relative);
            }
            return;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create directory for: ' . $relative);
        }
        $this->path($relative);
        $temporary = $before === null ? $path : $path . '.rcmaker-' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot write project file: ' . $relative);
        }
        $complete = false;
        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $count = fwrite($stream, substr($contents, $offset));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException('Incomplete project write: ' . $relative);
                }
                $offset += $count;
            }
            if (!fflush($stream)) {
                throw new \RuntimeException('Cannot flush project file: ' . $relative);
            }
            $complete = true;
        } finally {
            fclose($stream);
            if (!$complete) {
                unlink($temporary);
            }
        }
        if ($before !== null) {
            try {
                $this->assertExpected($relative, $before);
                @chmod($temporary, fileperms($path) & 0777);
                if (!rename($temporary, $path)) {
                    throw new \RuntimeException('Cannot replace project file: ' . $relative);
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        } elseif ($relative === '.env' || str_starts_with($relative, 'data/migration/')) {
            @chmod($path, 0600);
        }
    }
}
