# Stopwatch Compatibility Entries

[简体中文](README.md) | English

Legacy `RC\Helper\Stopwatch\...` classes alias pristine Symfony Stopwatch 8.1.x instead of maintaining a renamed timing engine.

A compatible Composer installation wins; otherwise pristine 8.1.0 loads from `src/ThirdParty/symfony-stopwatch/`. Only the required original Service Contracts `ResetInterface` is bundled, not a complete contracts package. Install other interfaces/traits through Composer.

Framework calls use `RC\Stopwatch` and `stopwatch($name)`. Independent/asynchronous tasks can create their own timer objects. See [migration notes](../../../data/docs/stopwatch-migration.en.md) for compatibility, precision and lifecycle.
