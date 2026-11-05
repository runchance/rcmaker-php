<?php

namespace RC\Helper\Excel;

use avadim\FastExcelReader\AbstractSheet;
use RC\Helper\XlsxReader;

/** Public sheet handle that keeps its owning import alive, including fluent temporary-reader chains. */
final class SheetView
{
    /** A handle owns the facade; the facade never stores handles, so there is no reference cycle. */
    public function __construct(private readonly XlsxReader $owner, private readonly AbstractSheet $sheet)
    {
    }

    /** Forward upstream sheet features and keep fluent calls on this lifetime-aware handle. */
    public function __call(string $name, array $arguments): mixed
    {
        $this->sheet->excel->assertOpen();
        if (!is_callable([$this->sheet, $name]) || (new \ReflectionMethod($this->sheet, $name))->isStatic()) {
            throw new \BadMethodCallException('Unknown worksheet method: ' . $name);
        }
        $result = $this->sheet->$name(...$arguments);
        if ($result === $this->sheet) {
            return $this;
        }
        return $result instanceof \Generator ? $this->keepAlive($result) : $result;
    }

    /** A generator must retain this handle even if the caller discards the original sheet variable. */
    private function keepAlive(\Generator $rows): \Generator
    {
        foreach ($rows as $key => $row) {
            $this->sheet->excel->assertOpen();
            yield $key => $row;
            $this->sheet->excel->assertOpen();
        }
    }
}
