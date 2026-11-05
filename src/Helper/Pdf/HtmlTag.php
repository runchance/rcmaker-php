<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use InvalidArgumentException;

/** Tokenize only callback boundaries; HTML layout remains in the upstream renderer. */
final class HtmlTag
{
    private const TAG = '~\G<(?:[^<>"\']+|"[^"]*"|\'[^\']*\')*>~s';
    private const ATTRIBUTE = <<<'REGEX'
~\G\s+([^\s=/<>"'`]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?~s
REGEX;

    /** Return HTML strings and tcpdf attribute arrays without treating comments, raw text or attributes as callbacks. */
    public static function split(string $html, bool $parseAttributes = true): array
    {
        $parts = [];
        $plain = '';
        $offset = 0;
        $length = strlen($html);
        while (($open = strpos($html, '<', $offset)) !== false) {
            $plain .= substr($html, $offset, $open - $offset);
            if (substr_compare($html, '<!--', $open, 4) === 0) {
                $close = strpos($html, '-->', $open + 4);
                $end = $close === false ? $length : $close + 3;
            } elseif (substr_compare($html, '<![CDATA[', $open, 9) === 0) {
                $close = strpos($html, ']]>', $open + 9);
                $end = $close === false ? $length : $close + 3;
            } elseif (preg_match(self::TAG, $html, $match, 0, $open) === 1) {
                $token = $match[0];
                $end = $open + strlen($token);
                if (preg_match('~\A<\s*(/?)\s*([a-z][a-z0-9:_-]*)\b~i', $token, $name) === 1) {
                    $tag = strtolower($name[2]);
                    if ($name[1] === '' && in_array($tag, ['script', 'style', 'textarea', 'title'], true)) {
                        $closing = '~</\s*' . $tag . '\s*>~i';
                        $end = preg_match($closing, $html, $close, PREG_OFFSET_CAPTURE, $end) === 1
                            ? $close[0][1] + strlen($close[0][0]) : $length;
                    } elseif ($tag === 'tcpdf') {
                        if ($plain !== '') { $parts[] = $plain; $plain = ''; }
                        if ($name[1] === '') {
                            $body = substr($token, strlen($name[0]), -1);
                            $body = preg_replace('~/\s*\z~', '', $body);
                            $parts[] = $parseAttributes ? self::attributes($body) : [];
                        }
                        $offset = $end;
                        continue;
                    }
                }
            } else {
                // An unfinished tag or quoted attribute makes the remainder inert, not a new callback search area.
                $plain .= substr($html, $open);
                $offset = $length;
                break;
            }
            $plain .= substr($html, $open, $end - $open);
            $offset = $end;
        }
        $plain .= substr($html, $offset);
        if ($plain !== '') { $parts[] = $plain; }
        return $parts;
    }

    /** Escape only inert callback-looking markup before the native renderer can interpret it independently. */
    public static function inert(string $html): string
    {
        return preg_replace('~<(?=/?\s*tcpdf\b)~i', '&lt;', $html);
    }

    /** Check public visibility from outside the document scope; protected business helpers stay inaccessible. */
    public static function callable(object $document, string $method): bool
    {
        return !str_starts_with($method, '__') && method_exists($document, $method)
            && is_callable([$document, $method]);
    }

    /** Invoke only the already-authorized public callback, retaining native argument errors and return behavior. */
    public static function invoke(object $document, string $method, array $parameters): mixed
    {
        return call_user_func_array([$document, $method], $parameters);
    }

    /** Parse quoted/unquoted HTML attributes with entity decoding and reject ambiguous duplicates. */
    private static function attributes(string $body): array
    {
        $attributes = [];
        $offset = 0;
        while (preg_match(self::ATTRIBUTE, $body, $match, PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            $name = strtolower($match[1]);
            if (array_key_exists($name, $attributes)) {
                throw new InvalidArgumentException('Duplicate tcpdf tag attribute: ' . $name);
            }
            $attributes[$name] = html_entity_decode($match[2] ?? $match[3] ?? $match[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $offset += strlen($match[0]);
        }
        if (trim(substr($body, $offset)) !== '') {
            throw new InvalidArgumentException('Malformed tcpdf tag attributes.');
        }
        return $attributes;
    }
}
