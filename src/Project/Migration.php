<?php

declare(strict_types=1);

namespace RC\Project;

/** Explicit dependency migration; business code, configuration and databases are never rewritten. */
final class Migration
{
    /** Preview replacing the old dependency; use apply() only after reviewing this plan. */
    public function plan(ProjectFiles $files, string $constraint = '*@stable'): array
    {
        if ($constraint === '' || preg_match('/dev|alpha|beta|(?:^|[^a-z])rc(?:[^a-z]|$)|@(?:dev|alpha|beta|rc)/i', $constraint)) {
            throw new \InvalidArgumentException('Use a stable release constraint for rcmaker-php.');
        }
        $before = $files->read('composer.json');
        if ($before === null) {
            throw new \RuntimeException('No composer.json found; use init for a new project.');
        }
        $manifest = Manifest::decode($before);
        $require = Manifest::object($manifest, 'require');
        $dev = property_exists($manifest, 'require-dev') ? $manifest->{'require-dev'} : new \stdClass();
        if (!$dev instanceof \stdClass) {
            throw new \RuntimeException('require-dev must be a JSON object.');
        }
        $legacy = isset($require->{Manifest::LEGACY}) || isset($dev->{Manifest::LEGACY});
        if (!$legacy) {
            if (isset($require->{Manifest::PACKAGE})) {
                return ['changes' => [], 'warnings' => ['Already uses runchance/rcmaker-php; no dependency changes.']];
            }
            throw new \RuntimeException('This is not a legacy RCMAKER project; use init or review dependencies manually.');
        }
        unset($require->{Manifest::LEGACY}, $dev->{Manifest::LEGACY});
        $require->{Manifest::PACKAGE} ??= $constraint;
        $require->php = Platform::PHP_CONSTRAINT;
        $warnings = [
            'Only composer.json changes. Existing index.php, windows.php, apps/, config/, public/ and .env are preserved.',
            'composer.lock and vendor/ stay untouched until you explicitly run Composer update.',
            'Review optional dependency versions and application behavior in a separate copy before production migration.',
        ];
        if (isset($manifest->config->platform->php)
            && !version_compare((string) $manifest->config->platform->php, '8.5.12', '>=')) {
            throw new \RuntimeException('config.platform.php pins PHP < 8.5.12; update that simulated version manually, then preview migration again.');
        }
        foreach (['index.php', 'windows.php'] as $entry) {
            $source = $files->read($entry);
            if ($source !== null && preg_match('~runchance[\\\\/]+rcmaker-framework~', $source)) {
                throw new \RuntimeException($entry . ' hard-codes the old vendor path; replace it with the project Composer autoloader before migrating.');
            }
        }
        return ['changes' => ['composer.json' => ['before' => $before, 'after' => Manifest::encode($manifest)]], 'warnings' => $warnings];
    }

    /** Back up the original manifest under external project data/, then apply one checked change. */
    public function apply(ProjectFiles $files, array $plan): ?string
    {
        if ($plan['changes'] === []) {
            return null;
        }
        $change = $plan['changes']['composer.json'];
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $prefix = 'data/migration/' . $id;
        $report = [
            'format' => 1,
            'before_sha256' => hash('sha256', $change['before']),
            'after_sha256' => hash('sha256', $change['after']),
            'note' => 'Manifest backup only; composer.lock, vendor, environment and databases are not migrated.',
        ];
        $files->commit([
            $prefix . '/composer.json' => ['before' => null, 'after' => $change['before']],
            $prefix . '/report.json' => ['before' => null, 'after' => json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"],
            'composer.json' => $change,
        ]);
        return $id;
    }

    /** Preview rollback of an unchanged migration manifest; never overwrite subsequent user edits. */
    public function rollback(ProjectFiles $files, string $id): array
    {
        if (!preg_match('/\A\d{8}-\d{6}-[a-f0-9]{12}\z/D', $id)) {
            throw new \InvalidArgumentException('Invalid migration backup ID.');
        }
        $prefix = 'data/migration/' . $id;
        $original = $files->read($prefix . '/composer.json');
        $reportJson = $files->read($prefix . '/report.json');
        if ($original === null || $reportJson === null) {
            throw new \RuntimeException('Migration backup is incomplete.');
        }
        $report = json_decode($reportJson, true, 512, JSON_THROW_ON_ERROR);
        $current = $files->read('composer.json');
        if (($report['format'] ?? null) !== 1
            || !hash_equals((string) ($report['before_sha256'] ?? ''), hash('sha256', $original))) {
            throw new \RuntimeException('Migration backup checksum is invalid.');
        }
        if ($current === $original) {
            return ['changes' => [], 'warnings' => ['Manifest already restored.']];
        }
        if ($current === null || !hash_equals((string) ($report['after_sha256'] ?? ''), hash('sha256', $current))) {
            throw new \RuntimeException('composer.json has changed after migration; automatic rollback refused.');
        }
        return [
            'changes' => ['composer.json' => ['before' => $current, 'after' => $original]],
            'warnings' => ['Only the manifest is restored; align composer.lock and vendor with Composer after reviewing the rollback.'],
        ];
    }
}
