<?php

declare(strict_types=1);

namespace RC\Helper\Captcha;

require_once __DIR__ . '/bootstrap.php';

/** Generate ASCII challenge phrases with the OS CSPRNG; e.g. new PhraseBuilder(5, '0123456789'). */
class PhraseBuilder extends \Gregwar\Captcha\PhraseBuilder implements PhraseBuilderInterface
{
    private static ?\Random\Randomizer $random = null;

    /** Generate a phrase, preserving the upstream mutable length/alphabet API without a weak RNG fallback. */
    public function build(?int $length = null, ?string $charset = null): string
    {
        $length ??= $this->length;
        $charset ??= $this->charset;
        if ($length < 1 || $length > 256 || $charset === '' || preg_match('/[^\x21-\x7e]/', $charset)) {
            throw new \InvalidArgumentException('Captcha requires 1-256 characters from a nonempty printable ASCII alphabet.');
        }
        $this->length = $length;
        $this->charset = $charset;
        self::$random ??= new \Random\Randomizer(new \Random\Engine\Secure());
        return self::$random->getBytesFromString($charset, $length);
    }
}
