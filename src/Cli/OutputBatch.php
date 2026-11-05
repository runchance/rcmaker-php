<?php

declare(strict_types=1);

namespace RC\Cli;

use RuntimeException;
use Throwable;

/** Privately prepare CLI outputs and roll back ordinary publication failures; not crash recovery or a live upgrade. */
final class OutputBatch
{
    private array $items = [];
    private array $workspaces = [];
    private array $locks = [];
    private static array $activeLocks = [];
    private bool $committed = false;
    private bool $recoveryRequired = false;

    /** Serialize encryption for one project without putting a lock inside a directory being encrypted in place. */
    public function guardProject(string $root): void
    {
        $key = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
        $this->lock(rtrim(sys_get_temp_dir(), '/\\') . '/.rcmaker-encrypt-' . hash('sha256', $key) . '.lock');
    }

    /** Reserve a file/directory destination; return a private, initially absent path for its producer. */
    public function stage(string $target, bool $directory, bool $replace, bool $lock = true): string
    {
        if ($this->committed || $this->recoveryRequired) { throw new RuntimeException('Publication batch cannot be reused.'); }
        $target = self::target($target, $directory);
        foreach ($this->items as $item) {
            if (Filesystem::pathContains($target, $item['target']) || Filesystem::pathContains($item['target'], $target)) {
                throw new RuntimeException('Publication outputs must not overlap: ' . $target);
            }
        }
        $parent = dirname($target);
        Filesystem::mkdir($parent);
        if ($lock) {
            $name = PHP_OS_FAMILY === 'Windows' ? strtolower(basename($target)) : basename($target);
            $this->lock($parent . '/.rcmaker-output-' . substr(hash('sha256', $name), 0, 24) . '.lock');
        }
        $original = $this->snapshot($target, $directory);
        if ($original !== null && !$replace) { throw new RuntimeException('Output already exists; enable overwrite: ' . $target); }
        if (!isset($this->workspaces[$parent])) {
            $candidate = $parent . '/.rcmaker-publish-' . bin2hex(random_bytes(12));
            if (!@mkdir($candidate, 0700)) { throw new RuntimeException('Cannot create a private publication workspace.'); }
            // Own cleanup only after mkdir succeeds, never after a random-name collision.
            $this->workspaces[$parent] = $candidate;
        }
        $workspace = $this->workspaces[$parent];
        $id = count($this->items);
        $prepared = $workspace . '/next-' . $id . '-' . basename($target);
        $this->items[] = ['target' => $target, 'directory' => $directory, 'original' => $original,
            'prepared' => $prepared, 'backup' => $workspace . '/previous-' . $id,
            'published' => false, 'moved' => false];
        return $prepared;
    }

    /** Include these generated paths in a source collector's exclusions, including stable cooperative locks. */
    public function generatedPaths(): array
    {
        return [...array_values($this->workspaces), ...array_keys($this->locks)];
    }

    /** Stage runtime cache metadata under the same cooperative lock used by ArtifactRepository. */
    public function guardArtifact(string $target): void
    {
        $this->lock(ArtifactRepository::cacheSidecars($target)[1]);
    }

    /** Prepare every backup before the first write; restore published predecessors on a synchronous failure. */
    public function commit(): void
    {
        if ($this->committed || $this->recoveryRequired) { throw new RuntimeException('Publication batch cannot be reused.'); }
        foreach ($this->items as $item) {
            self::target($item['prepared'], $item['directory']);
            if (!file_exists($item['prepared'])) { throw new RuntimeException('Prepared output is missing: ' . $item['target']); }
            if ($this->snapshot($item['target'], $item['directory']) !== $item['original']) {
                throw new RuntimeException('Output changed during preparation: ' . $item['target']);
            }
            if (!$item['directory'] && $item['original'] !== null) {
                if (!copy($item['target'], $item['backup'])
                    || !@chmod($item['backup'], $item['original']['mode'] & 0777)
                    || hash_file('sha256', $item['backup']) !== $item['original']['sha256']) {
                    throw new RuntimeException('Cannot back up previous output: ' . $item['target']);
                }
            }
        }
        try {
            foreach ($this->items as &$item) {
                if ($this->snapshot($item['target'], $item['directory']) !== $item['original']) {
                    throw new RuntimeException('Output changed before publication: ' . $item['target']);
                }
                if ($item['directory'] && $item['original'] !== null) {
                    $this->rename($item['target'], $item['backup']);
                    $item['moved'] = true;
                }
                $this->rename($item['prepared'], $item['target']);
                $item['published'] = true;
            }
            unset($item);
            $this->committed = true;
        } catch (Throwable $error) {
            unset($item);
            $failures = [];
            for ($index = count($this->items) - 1; $index >= 0; --$index) {
                $item = $this->items[$index];
                try {
                    if ($item['directory']) {
                        if ($item['published']) { $this->rename($item['target'], $item['prepared']); }
                        if ($item['moved']) { $this->rename($item['backup'], $item['target']); }
                    } elseif ($item['published']) {
                        if ($item['original'] !== null) { $this->rename($item['backup'], $item['target']); }
                        elseif (!@unlink(Filesystem::fileTarget($item['target']))) { throw new RuntimeException('Cannot remove new output.'); }
                    }
                } catch (Throwable $rollback) { $failures[] = $item['target'] . ': ' . $rollback->getMessage(); }
            }
            if ($failures !== []) {
                $this->recoveryRequired = true;
                throw new RuntimeException('Publication failed: ' . $error->getMessage() . '; automatic rollback was incomplete. Keep the recovery directories: '
                    . implode(', ', $this->workspaces) . '. ' . implode('; ', $failures), 0, $error);
            }
            throw $error;
        }
    }

    /** Release all locks; preserve recovery copies if rollback failed. Cleanup warnings do not undo a committed batch. */
    public function close(): array
    {
        $warnings = [];
        if (!$this->recoveryRequired) {
            foreach ($this->workspaces as $workspace) {
                try { Filesystem::removePath($workspace); }
                catch (Throwable $error) { $warnings[] = 'Private publication cleanup failed: ' . $workspace . ': ' . $error->getMessage(); }
            }
        }
        foreach ($this->locks as $path => $handle) {
            try { flock($handle, LOCK_UN); fclose($handle); }
            catch (Throwable $error) { $warnings[] = 'Publication lock release failed: ' . $error->getMessage(); }
            finally { unset(self::$activeLocks[$path]); }
        }
        $this->locks = [];
        return $warnings;
    }

    /** Reject roots, final links, type changes and multiply linked file destinations before allocating outputs. */
    public static function target(string $path, bool $directory): string
    {
        if (!$directory) { return Filesystem::fileTarget($path); }
        if (Filesystem::isLinkPath(Filesystem::absolute($path))) { throw new RuntimeException('Directory output cannot be a link.'); }
        $path = Filesystem::resolvePath($path);
        $windowsRoot = PHP_OS_FAMILY === 'Windows' && (preg_match('~^[A-Za-z]:/?$~D', $path) || preg_match('~^//[^/]+/[^/]+/?$~D', $path));
        if (dirname($path) === $path || $windowsRoot || (@lstat($path) !== false && !is_dir($path))) {
            throw new RuntimeException('Directory output must be an ordinary non-root directory.');
        }
        return $path;
    }

    /** Files are fingerprinted; directory children must remain immutable while preparing a whole-directory replacement. */
    private function snapshot(string $path, bool $directory): ?array
    {
        self::target($path, $directory);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) { return null; }
        $state = ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode']];
        if (!$directory) {
            $hash = hash_file('sha256', $path);
            if ($hash === false) { throw new RuntimeException('Cannot fingerprint previous output.'); }
            $state['sha256'] = $hash;
        }
        return $state;
    }

    /** Stable nonblocking locks coordinate cooperative builders, including in-process callbacks. */
    private function lock(string $path): void
    {
        $path = Filesystem::fileTarget($path);
        if (isset($this->locks[$path])) { return; }
        if (isset(self::$activeLocks[$path])) { throw new RuntimeException('An output operation is already running.'); }
        $handle = @fopen($path, 'x+b');
        if (is_resource($handle)) { @chmod($path, 0600); }
        else { $handle = @fopen($path, 'r+b'); }
        if (!is_resource($handle)) { throw new RuntimeException('Cannot open publication lock.'); }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) { throw new RuntimeException('An output operation is already running.'); }
            Filesystem::fileTarget($path); $opened = fstat($handle); $current = lstat($path);
            if ($opened === false || $current === false || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
                throw new RuntimeException('Publication lock changed during open.');
            }
            $this->locks[$path] = $handle; self::$activeLocks[$path] = true;
        } catch (Throwable $error) { fclose($handle); throw $error; }
    }

    /** Rename on the destination filesystem without deleting its predecessor; Windows sharing errors get a short retry. */
    private function rename(string $source, string $target): void
    {
        $renamed = @rename($source, $target);
        for ($attempt = 0; !$renamed && PHP_OS_FAMILY === 'Windows' && $attempt < 50; ++$attempt) {
            usleep(10000); $renamed = @rename($source, $target);
        }
        if (!$renamed) { throw new RuntimeException('Cannot publish or restore output: ' . $target); }
        clearstatcache(true, $source); clearstatcache(true, $target);
    }
}
