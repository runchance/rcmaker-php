<?php

namespace RC\Helper\Db\mix\Database;

require_once dirname(__DIR__) . '/bootstrap.php';

/** SQL template plus values, e.g. new Expr('amount + ?', 1); never interpolate untrusted values. */
class Expr extends \Mix\Database\Expr
{
    /** Internal compiler input; PDO interprets placeholders, not PHP printf. */
    public function sql(): string { return $this->expr; }

    /** Keep types intact until PDO binding. */
    public function bindings(): array { return $this->values; }

    /** Only parameter-free trusted SQL has a context-independent string representation. */
    public function __toString(): string
    {
        if ($this->values !== []) { throw new \LogicException('A bound Mix Expr belongs in the query builder, not SQL concatenation.'); }
        return $this->expr;
    }
}
