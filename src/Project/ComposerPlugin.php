<?php

declare(strict_types=1);

namespace RC\Project;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/** Optional first-install convenience; never initializes an existing app on update. */
final class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private bool $installedNow = false;

    /** Composer owns activation and allow-plugins consent; no application code is loaded here. */
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    /** Unloading the plugin must not remove project-owned files. */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    /** Removing the dependency does not delete applications, configuration or migration backups. */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /** Register only installation-time events, with no per-request hooks. */
    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'installed',
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'initialize',
        ];
    }

    /** Record a genuine first installation, not an update or a plain dump-autoload. */
    public function installed(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if (method_exists($operation, 'getPackage') && $operation->getPackage()->getName() === Manifest::PACKAGE) {
            $this->installedNow = true;
        }
    }

    /** Offer a safe template preview before Composer writes the application's autoloader. */
    public function initialize(Event $event): void
    {
        if (!$this->installedNow) {
            return;
        }
        $composer = $event->getComposer();
        $io = $event->getIO();
        $extra = $composer->getPackage()->getExtra();
        $setting = $extra['rcmaker']['initialize'] ?? null;
        if ($setting === false) {
            return;
        }
        try {
            // Respect COMPOSER=/other/path/composer.json, rather than assuming the shell's cwd.
            $manifestPath = \Composer\Factory::getComposerFile();
            if (basename($manifestPath) !== 'composer.json') {
                $io->write('<comment>RCMAKER: custom Composer filename detected; initialize the application explicitly.</comment>');
                return;
            }
            $root = realpath(dirname($manifestPath));
            $files = new ProjectFiles($root === false ? (getcwd() ?: '.') : $root);
            $initializer = new Initializer();
            if (!$initializer->isFresh($files)) {
                $io->write('<info>RCMAKER: existing project preserved. Use init --dry-run or migrate --dry-run explicitly.</info>');
                return;
            }
            $plan = $initializer->plan($files);
            $io->write('<info>RCMAKER: initialize application files in ' . $files->root . '?</info>');
            foreach (array_keys($plan['changes']) as $path) {
                $io->write('  + ' . $path);
            }
            if ($setting !== true && (!$io->isInteractive() || !$io->askConfirmation('Initialize now? [Y/n] ', true))) {
                $io->write('<comment>Skipped. Run php vendor/bin/rcmaker init when ready.</comment>');
                return;
            }
            $files->commit($plan['changes']);
            // Composer has already read the root manifest; update only this in-memory autoload map.
            $autoload = json_decode(json_encode($plan['manifest']->autoload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $composer->getPackage()->setAutoload($autoload);
            $io->write('<info>RCMAKER ready: php index.php start | php index.php interact</info>');
        } catch (\Throwable $error) {
            $io->writeError('<warning>RCMAKER initialization skipped: ' . $error->getMessage() . '. Run php vendor/bin/rcmaker init --dry-run.</warning>');
        }
    }
}
