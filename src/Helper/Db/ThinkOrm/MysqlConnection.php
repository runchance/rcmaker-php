<?php

namespace RC\Helper\Db\ThinkOrm;

/** Keep ThinkORM's MySQL execution and route selection, adding safe bound subquery transport. */
class MysqlConnection extends \think\db\connector\Mysql
{
    use BoundQueryConnection;
    use CachedQueryConnection;
    use ManagedCursor;

    protected const RC_BOUND_DIALECT = 'mysql';
    protected const RC_CACHE_DIALECT = 'mysql';

    private array $rcUnbufferedScopes = [];

    /** A cloned Query cursor owns a fresh mode scope, while native PDO/read-write sharing stays unchanged. */
    public function __clone()
    {
        $this->rcUnbufferedScopes = [];
    }

    /** New/reconnected PDOs enter an active unbuffered cursor before any statement executes, not on ordinary queries. */
    protected function createPdo($dsn, $username, $password, $params)
    {
        $pdo = parent::createPdo($dsn,$username,$password,$params);
        if ($this->rcUnbufferedScopes) { $this->rcUseUnbufferedPdo($pdo); }
        return $pdo;
    }

    /** Select the genuine native route first, then restore every participating PDO's exact original buffering flag. */
    protected function cursorUnbuffered(\think\db\BaseQuery $query, string $sql)
    {
        $scope = new \SplObjectStorage();
        $this->rcUnbufferedScopes[] = $scope;
        $completed = false;
        $failure = null;
        try {
            $master = $this->readMaster || (bool)$query->getOption('master') || $this->transTimes > 0;
            $pdo = !empty($this->config['deploy']) ? ($master ? $this->linkWrite : $this->linkRead) : $this->linkID;
            // Opening/reopening stays inside native getPDOStatement() and its opt-in retry boundary.
            if ($pdo instanceof \PDO) { $this->rcUseUnbufferedPdo($pdo); }
            yield from $this->getCursor($query,$sql,$query->getModel());
            $completed = true;
        } catch (\Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            array_pop($this->rcUnbufferedScopes);
            $restoreFailure = null;
            foreach ($scope as $pdo) {
                $previous = $scope[$pdo];
                try {
                    if (!$pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY,$previous)) {
                        throw \RC\Helper\Db\PdoFailure::fromPdo($pdo,'Cannot restore MySQL cursor buffering.');
                    }
                } catch (\Throwable $error) { $restoreFailure ??= $error; }
            }
            if ($restoreFailure !== null) {
                $this->rcCursorCleanup(static function () use ($restoreFailure): void { throw $restoreFailure; },$completed,$failure);
            }
        }
    }

    /** Remember a PDO only once per cursor scope; a pre-existing false flag is not silently replaced by true. */
    private function rcUseUnbufferedPdo(\PDO $pdo): void
    {
        $scope = $this->rcUnbufferedScopes[array_key_last($this->rcUnbufferedScopes)];
        if (!$scope->offsetExists($pdo)) {
            $scope[$pdo] = (bool)$pdo->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY);
            if (!$pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY,false)) {
                throw \RC\Helper\Db\PdoFailure::fromPdo($pdo,'Cannot enable the MySQL unbuffered cursor.');
            }
        }
    }

    /** Use RC's grouped-count adapter unless the developer explicitly selects a custom query class. */
    public function getQueryClass(): string
    {
        return $this->getConfig('query') ?: Query::class;
    }

    /** Respect an explicit developer builder instead of silently overriding its grammar. */
    public function getBuilderClass(): string
    {
        return $this->getConfig('builder') ?: MysqlBuilder::class;
    }
}
