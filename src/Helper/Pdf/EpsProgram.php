<?php

declare(strict_types=1);

namespace RC\Helper\Pdf;

use RuntimeException;

/**
 * Numeric Illustrator-vector compatibility, not a PostScript interpreter.
 * Semantics follow TCPDF's ImageEps by Valentin Schmidt and Nicola Asuni.
 * SPDX-License-Identifier: LGPL-3.0-or-later
 * See ../../ThirdParty/tcpdf/LICENSE.TXT for the upstream license.
 */
final class EpsProgram
{
    private const NUMBER = '[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)';

    /** Keep parsed operators separate so malformed input cannot partially modify a document. */
    private function __construct(public readonly array $box, private readonly array $operations)
    {
    }

    /** Parse the old vector subset; unknown PostScript is never executed or copied into PDF operators. */
    public static function parse(string $data, bool $fixOutValues): self
    {
        $header = strpos($data, '%!PS-Adobe');
        if ($header === false) {
            throw new RuntimeException('TCPDF EPS: a PostScript EPS header is required.');
        }
        $data = substr($data, $header);
        if (preg_match('/%%Creator:[^\r\n]*Adobe Illustrator[^\r\n]*?\s([0-9]+(?:\.[0-9]+)*)\s*(?:\r|\n|$)/', $data, $creator)
            && (float) $creator[1] >= 9) {
            throw new RuntimeException('TCPDF EPS: Illustrator 9 or later is not supported by the legacy vector importer.');
        }
        if (!preg_match('/^%%BoundingBox:\s*(' . self::NUMBER . ')\s+(' . self::NUMBER . ')\s+('
            . self::NUMBER . ')\s+(' . self::NUMBER . ')\s*$/m', $data, $match)) {
            throw new RuntimeException('TCPDF EPS: a numeric BoundingBox is required.');
        }
        $box = array_map('floatval', array_slice($match, 1, 4));
        if (!array_all($box, static fn(float $value): bool => is_finite($value)) || $box[2] <= $box[0] || $box[3] <= $box[1]) {
            throw new RuntimeException('TCPDF EPS: invalid BoundingBox dimensions.');
        }
        $start = strpos($data, '%%EndSetup');
        $start = $start === false ? strpos($data, '%%EndProlog') : $start;
        $start = $start === false ? strpos($data, '%%BoundingBox') : $start;
        $body = substr($data, $start);
        $end = strpos($body, '%%PageTrailer');
        $end = $end === false ? strpos($body, 'showpage') : $end;
        if ($end !== false) {
            $body = substr($body, 0, $end);
        }
        $lines = preg_split('/[\r\n]+/', $body, -1, PREG_SPLIT_NO_EMPTY);
        $arity = ['m'=>2,'l'=>2,'v'=>4,'y'=>4,'c'=>6,'k'=>4,'K'=>4,'g'=>1,'G'=>1,
            's'=>0,'S'=>0,'J'=>1,'j'=>1,'w'=>1,'M'=>1,'n'=>0];
        $aliases = ['Y'=>'y','N'=>'n','V'=>'v','L'=>'l','C'=>'c'];
        $operations = [];
        $compound = 0;
        foreach ($lines as $index => $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '%')) {
                continue;
            }
            if ($line === '*u') { ++$compound; continue; }
            if ($line === '*U') { --$compound; continue; }
            $space = strrpos($line, ' ');
            $command = $space === false ? $line : substr($line, $space + 1);
            $arguments = $space === false ? '' : trim(substr($line, 0, $space));
            if ($command === 'd') {
                if (!preg_match('/^\[([^\]]*)\]\s+(' . self::NUMBER . ')$/D', $arguments, $dash)) {
                    throw new RuntimeException('TCPDF EPS: invalid dash operands.');
                }
                $values = self::numbers($dash[1]);
                if (array_any($values, static fn(string $value): bool => (float) $value < 0)
                    || (float) $dash[2] < 0 || $values !== [] && !array_any($values, static fn(string $value): bool => (float) $value > 0)) {
                    throw new RuntimeException('TCPDF EPS: invalid dash pattern.');
                }
                $operations[] = ['d', '[' . implode(' ', $values) . '] ' . $dash[2]];
                continue;
            }
            if ($command === 'x' || $command === 'X') {
                $name = null;
                if (preg_match('/\(([^)]*)\)/', $arguments, $spot)) {
                    $name = $spot[1];
                    $arguments = str_replace($spot[0], '', $arguments);
                    if ($name === '' || str_contains($name, "\0")) {
                        throw new RuntimeException('TCPDF EPS: invalid spot color name.');
                    }
                }
                $values = self::numbers($arguments);
                if (count($values) !== ($name === null ? 4 : 5)) {
                    throw new RuntimeException('TCPDF EPS: invalid custom color operands.');
                }
                $operations[] = [$command, $values, $name];
                continue;
            }
            if ($command === 'Xa' || $command === 'XA') {
                $values = self::numbers($arguments);
                if (count($values) < 3) {
                    throw new RuntimeException('TCPDF EPS: invalid RGB operands.');
                }
                $operations[] = [$command === 'Xa' ? 'rg' : 'RG', implode(' ', array_slice($values, -3))];
                continue;
            }
            if (in_array($command, ['f','F','b','B'], true)) {
                if ($command === 'b' || $command === 'B') {
                    $operations[] = [$command . '*', ''];
                } elseif ($compound <= 0 || array_any(array_slice($lines, $index + 1, 4),
                    static fn(string $next): bool => in_array(trim($next), ['U','*U'], true))) {
                    $operations[] = ['f*', ''];
                }
                continue;
            }
            $command = $aliases[$command] ?? $command;
            if (!isset($arity[$command])) {
                continue;
            }
            $values = self::numbers($arguments);
            if (count($values) !== $arity[$command]) {
                throw new RuntimeException('TCPDF EPS: invalid operands for ' . $command . '.');
            }
            if ($fixOutValues && in_array($command, ['m','l'], true)
                && ((float) $values[0] < $box[0] || (float) $values[0] > $box[2]
                    || (float) $values[1] < $box[1] || (float) $values[1] > $box[3])) {
                continue;
            }
            $operations[] = [$command, implode(' ', $values)];
        }
        return new self($box, $operations);
    }

    /** Build safe PDF operators, delegating spot registration to the document's existing color engine. */
    public function toPdf(callable $spotColor): string
    {
        $output = '';
        foreach ($this->operations as $operation) {
            [$command, $arguments] = $operation;
            if ($command === 'x' || $command === 'X') {
                $name = $operation[2];
                $output .= $name === null ? implode(' ', $arguments) . ($command === 'x' ? " k\n" : " K\n")
                    : $spotColor($name, array_map('floatval', array_slice($arguments, 0, 4)), 1 - (float) $arguments[4], $command === 'X');
            } else {
                $output .= ($arguments === '' ? '' : $arguments . ' ') . $command . "\n";
            }
        }
        return $output;
    }

    /** Only finite PDF decimal operands are allowed; strings, procedures and executable tokens are rejected. */
    private static function numbers(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }
        $values = preg_split('/\s+/', $input);
        foreach ($values as $value) {
            if (!preg_match('/^' . self::NUMBER . '$/D', $value) || !is_finite((float) $value)) {
                throw new RuntimeException('TCPDF EPS: only finite numeric drawing operands are supported.');
            }
        }
        return $values;
    }
}
