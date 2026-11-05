<?php

declare(strict_types=1);

namespace RC\Helper\Db\ThinkOrm;

use Closure;
use PDO;
use think\db\BaseQuery;
use think\db\CacheItem;

/** Keep ThinkORM 4.0.51 execution/result conventions while fixing automatic cache identities in RC only. */
trait CachedQueryConnection
{
    /** Automatic caches never consume or publish data while native transaction state is active. */
    public function allowsAutomaticQueryCache(): bool
    {
        return $this->transTimes === 0;
    }

    /** Ordinary SQL remains upstream; opted-in caches use compiled SQL and parameters before consulting storage. */
    protected function pdoQuery(BaseQuery $query, $sql, ?bool $master = null): array
    {
        if (!$query->getOption('cache') && !$query->getOption('scope')) { return parent::pdoQuery($query,$sql,$master); }
        $query->parseOptions();
        $bindings = $query->getBind();
        $cache = $query->getOption('cache');
        $item = null;
        if ($cache && $cache[0] !== true) {
            $item = $this->parseCache($query,$cache);
            [$hit,$data] = $this->rcReadCache($query,$item,true);
            if ($hit) { return $data; }
        }
        if ($sql instanceof Closure) {
            $sql = $sql($query);
            $bindings = array_merge($bindings,$query->getBind());
        }
        $master ??= (bool)$query->getOption('master');
        if ($cache && $cache[0] === true) {
            $item = $this->rcAutoCache($query,$sql,$bindings,['rows',$master]);
            [$hit,$data] = $this->rcReadCache($query,$item,true);
            if ($hit) { return $data; }
        }
        $procedure = $query->getOption('procedure') || in_array(strtolower(substr(trim($sql),0,4)),['call','exec']);
        $this->getPDOStatement($sql,$bindings,$master,$procedure);
        $result = $this->getResult($procedure);
        $this->rcWriteCache($query,$item,$result);
        return $result;
    }

    /** Scalar caches include the selected field, limit behavior and missing-row default; hit/miss restores options alike. */
    public function value(BaseQuery $query, string $field, $default = null, bool $one = true)
    {
        if (($query->getOption('cache')[0] ?? null) !== true && !$query->getOption('scope')) {
            return parent::value($query,$field,$default,$one);
        }
        $options = $query->parseOptions();
        $query->setOption('field',(array)$field)->group('');
        try {
            $sql = $this->builder->select($query,$one);
            $bindings = $query->getBind();
            $item = $this->rcAutoCache($query,$sql,$bindings,['value',$options['master'],$default]);
        } finally {
            $this->rcRestoreField($query,$options);
            if (isset($options['group'])) { $query->setOption('group',$options['group']); }
        }
        [$hit,$data] = $this->rcReadCache($query,$item);
        if ($hit) { return $data; }
        $statement = $this->getPDOStatement($sql,$bindings,$options['master']);
        $result = $statement->fetchColumn();
        $result = $result === false ? $default : $result;
        $this->rcWriteCache($query,$item,$result);
        return $result;
    }

    /** Column caches retain upstream aliases/keyed rows while distinguishing result shape from scalar/row caches. */
    public function column(BaseQuery $query, string|array $column, string $key = ''): array
    {
        if (($query->getOption('cache')[0] ?? null) !== true && !$query->getOption('scope')) {
            return parent::column($query,$column,$key);
        }
        $options = $query->parseOptions();
        $key = empty($key) || trim($key) === '' ? null : $key;
        if (is_string($column)) {
            $column = trim($column);
            if ($column !== '*') { $column = array_map('trim',explode(',',$column)); }
        } elseif (in_array('*',$column)) { $column = '*'; }
        $fields = $column;
        if ($column !== '*' && $key && !in_array($key,$column)) { $fields[] = $key; }
        $query->setOption('field',(array)$fields);
        try {
            $sql = $this->builder->select($query);
            $bindings = $query->getBind();
            $item = $this->rcAutoCache($query,$sql,$bindings,['column',$options['master'],$column,$key]);
        } finally { $this->rcRestoreField($query,$options); }
        [$hit,$data] = $this->rcReadCache($query,$item);
        if ($hit) { return $data; }
        $statement = $this->getPDOStatement($sql,$bindings,$options['master']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (is_string($key) && str_contains($key,'.')) { [, $key] = explode('.',$key); }
        if (!$rows) { $result = []; }
        elseif ($column !== '*' && count($column) === 1) {
            $column = array_shift($column);
            if (str_contains($column,' ')) { $column = substr(strrchr(trim($column),' '),1); }
            if (str_contains($column,'.')) { [, $column] = explode('.',$column); }
            if (str_contains($column,'->')) { $column = $this->builder->parseKey($query,$column); }
            $result = array_column($rows,$column,$key);
        } elseif ($key) { $result = array_column($rows,null,$key); }
        else { $result = $rows; }
        $this->rcWriteCache($query,$item,$result);
        return $result;
    }

    /** Create native cache items and retain explicit keys, expiry and tags; only automatic keys change. */
    private function rcAutoCache(BaseQuery $query, string $sql, array $bindings, array $shape): ?CacheItem
    {
        $cache = $query->getOption('cache');
        if (!$cache) { return null; }
        if ($cache[0] === true) {
            if (!$this->allowsAutomaticQueryCache()) { return null; }
            $shape[] = ['read_master' => (bool)$this->readMaster];
            $cache[0] = QueryCacheKey::make($this,static::RC_CACHE_DIALECT,$sql,$bindings,$shape);
            if ($cache[0] === null) { return null; }
        }
        return $this->parseCache($query,$cache);
    }

    /** Preserve native has/get, null-miss and forced-refresh behavior without confusing null with an empty array or zero. */
    private function rcReadCache(BaseQuery $query, ?CacheItem $item, bool $arraysOnly = false): array
    {
        if ($item !== null && !$query->getOption('force_cache') && $this->cache->has($item->getKey())) {
            $value = $this->cache->get($item->getKey());
            if ($value !== null && (!$arraysOnly || is_array($value))) { return [true,$value]; }
        }
        return [false,null];
    }

    /** Reuse upstream tag/expiry publication and its opt-in empty-result policy. */
    private function rcWriteCache(BaseQuery $query, ?CacheItem $item, mixed $result): void
    {
        if ($item !== null && ($query->getOption('cache_always') || !empty($result))) {
            $item->set($result);
            $this->cacheData($item);
        }
    }

    /** Cached scalar/column reads must not leave temporary projection fields on the user's query object. */
    private function rcRestoreField(BaseQuery $query, array $options): void
    {
        if (isset($options['field'])) { $query->setOption('field',$options['field']); }
        else { $query->removeOption('field'); }
    }
}
