<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

/** Internal count compiler; ordinary application queries keep the upstream parameter-name generator. */
final class GroupQuery extends \think\db\Query
{
    private int $parameterSequence = 0;

    /** Generate stable, collision-free names so the same grouped query can reuse its automatic cache key. */
    public function bindValue($value, ?int $type = null, ?string $name = null)
    {
        if (!$name) {
            do { $name = '__rc_group_bind_' . ++$this->parameterSequence; }
            while (array_key_exists($name, $this->bind));
        }
        return parent::bindValue($value, $type, $name);
    }
}
