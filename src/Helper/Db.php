<?php
namespace RC\Helper;
use RC\Helper\Paginator;
use RC\Request;
use RC\Config;
use RC\Helper\Db\Medoo\Medoo;
use PDO;
class Db{
	use \RC\Helper\Db\Laravel\SdbMethods;
	private $request = null;
	private $connect = null;
	private $conn = null;
	private $engine = null;
	private $type = null;
	private $page = 1;
	private $order = null;
	private $group = null;
	private $having = null;
	private $join = null;
	private $where = null;
	private $whereExp = null;
	private $bind = null;
	private $limit = null;
	private $offset = null;
	private $field = null;
	private $lock = null;
	// SQL stays null until sticky debug() is enabled; normal operations need no redundant clear.
	private $sql = null;
	private $enbleDebug = null;
	private $error = null;
	private ?\WeakReference $debugStatement = null;
	private static array $diagnosticStatementProperties = [];
	private $insertid = null;
	private $instance = null;
	private $medooJoin = null;
	private $medooWhere = null;
	private $medooWhereRaw = null;
	private $medooOp = null;
	private $table = null;
	private static $_Query =null;
	private static $_Builder =null;
	private static $_DriverMap = [
		'mongodb'=>'mongo'
	];
	public function __construct($request,$engine=null,$type=null,$id=1){
		$this->engine = $engine ?? Config::get('db','default_frame');
		$this->type = $type ?? Config::get('db','default');
		// SDB keeps the native connection; legacy Think static facades never wrap its query path.
		$this->connect = \database($this->engine,$this->type,$id,native:true);
		$this->request = $request;
		
	}
	public function q($sql,$fetch=false){
		return $this->query($sql,$fetch);
	}
	public function query($sql,$fetch=false){
		$this->error = null;
		if ($this->enbleDebug) { $this->sql = null; }
		switch($this->engine){
			case 'think':
				try {
					if($fetch){
						$result = $this->connect->query($sql);
					}else{
						$result = ($this->connect->execute($sql)===false) ? false : true;
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $sql; }
				} 
			break;
			case 'laravel':
				if ($this->connect->getDriverName() === 'mongodb') {
					throw new \InvalidArgumentException('MongoDB does not execute SQL strings; use document queries or native MQL.');
				}
				try {
					if($fetch){
						$result = $this->connect->select($sql);
					}else{
						$result = $this->connect->unprepared($sql);
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $sql; }
				} 
			break;
			case 'medoo':
				try {
					if($fetch){
						$result = $this->connect->query($sql)->fetchAll(PDO::FETCH_ASSOC);
					}else{
						$result = $this->connect->query($sql) ? true : false;
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $sql; }
				}
			break;
		}
	}

	public function instance(){
		return $this->connect;
	}

	public function setConn($connect){
		$this->connect = $connect;
	}

	public function debug(){
		$this->enbleDebug = true;
		return $this;
	}

	public function conn(){
		return $this->conn;
	}

	public function native(){
		return $this->connect;
	}

	public function table($t=null){
		$this->table = $t;
		$this->removeOption(['join','where','whereExp','order','offset','limit','group','having','bind','field','lock','error','medooJoin','medooWhere','medooWhereRaw','medooOp']);
		return $this;
	}

	public function t($t=null){
		return $this->table($t);
	}

	public function sql(){
		return $this->sql;
	}

	public function id(){
		return $this->insertid;
	}


	public function error(){
		return $this->error;
	}

	public function e(){
		return $this->error();
	}

	public function bind($exp='join', ...$bind){
		$this->bind[] = [$exp,$bind];
		return $this;
	}

	public function b($exp='join', ...$bind){
		return $this->bind($exp,...$bind);
	}

	public function join(...$join){
		$this->join[] = $join;
		return $this;
	}
	public function j(...$join){
		return $this->join(...$join);
	}

	/** Add a condition. Values are bound; a single SQL string is trusted raw SQL. */
	public function where(...$where){
		if (!$where) {
			throw new \InvalidArgumentException('where() requires a condition.');
		}
		if (!is_string($where[0]) && is_callable($where[0])) {
			$this->where[] = [$where[0], 'AND'];
		} else {
			$this->where[] = count($where) > 1 ? $where : ' ' . $where[0];
		}
		return $this;
	}

	public function w(...$where){
		return $this->where(...$where);
	}

	/** Add an OR condition/group. Prefer a closure to make mixed boolean intent explicit. */
	public function whereOr(...$where){
		if (!$where) {
			throw new \InvalidArgumentException('whereOr() requires a condition.');
		}
		if (count($where) === 1 && is_string($where[0])) {
			return $this->whereRaw($where[0], [], 'OR');
		}
		$where[] = 'OR';
		$this->where[] = $where;
		return $this;
	}

	/** Add trusted SQL with bound values, e.g. whereRaw('score > :min', [':min' => 10]). */
	public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'){
		$boolean = strtoupper($boolean);
		if (!in_array($boolean, ['AND', 'OR'], true) || trim($sql) === '') {
			throw new \InvalidArgumentException('whereRaw() requires SQL and an AND/OR connector.');
		}
		if ($this->engine === 'medoo') {
			$this->where[] = [Medoo::raw($sql, $bindings), $boolean];
		} elseif ($this->engine === 'think' || $this->engine === 'laravel') {
			$this->where[] = ['raw' => $sql, 'bindings' => $bindings, 'boolean' => $boolean];
		} else {
			$this->bind('whereRaw', $sql, $bindings, $boolean);
		}
		return $this;
	}

	public function wo(...$where){
		return $this->whereOr(...$where);
	}

	public function whereExp($exp, ...$where){
		$this->whereExp[] = [$exp, $where];
		return $this;
	}

	public function we($exp, ...$where){
		return $this->whereExp($exp, ...$where);
	}

	public function group($group){
		$this->group = $group;
		return $this;
	}

	public function g($group){
		return $this->group($group);
	}

	public function having($having){
		$this->having = $having;
		return $this;
	}

	public function h($having){
		return $this->having($having);
	}

	public function order($order){
		$this->order = $order;
		return $this;
	}

	public function o($order){
		return $this->order($order);
	}

	/** Bound reads/updates/deletes: limit(0) is empty; limit(20, 40) skips forty rows. */
	public function limit($limit=10,$offset=null){
		$nextLimit = self::queryBound($limit, 'limit');
		$nextOffset = $offset === null ? null : self::queryBound($offset, 'offset');
		$this->limit = $nextLimit;
		$this->offset = $nextOffset;
		return $this;
	}

	/** Validate once at the public setter, before replacing an existing safe bound. */
	private static function queryBound($value, string $name): int {
		$parsed = is_int($value) || is_string($value)
			? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
		if ($parsed === false) { throw new \InvalidArgumentException('SDB ' . $name . ' must be a nonnegative integer.'); }
		return $parsed;
	}

	public function l($limit=10,$offset=null){
		return $this->limit($limit,$offset);
	}

	private function removeOption(array $opts){
		foreach($opts as $opt){
			if($opt=='medooWhereRaw' && strtolower($this->engine)=='medoo'){
			    $this->connect->buildRCWhere([]);
			}
			$this->{$opt} = null;
		}
	}

	/** Dispatch each requested step once, preserving caller order and the native engine's builder behavior. */
	private function build($builders = [], $builderParms = []){
		$this->error = null;
		if ($this->enbleDebug) {
			$this->sql = null;
			if ($this->engine !== 'laravel') { $this->debugStatement = $this->debugStatementReference(); }
		}
		if ($this->engine === 'laravel' && in_array('table', $builders, true)) { $this->laravelRootWritePdo = false; }
		if ($this->engine === 'medoo') {
			$this->medooWhere = $this->compileMedooConditions();
			$this->medooWhereRaw = null;
			$this->medooJoin = null;
			$this->medooOp = null;
		}
		foreach($builders as $builder){
			switch ($builder) {
				case 'table':
					if($this->table){
						switch($this->engine){
							case 'think':
								if(strpos(strtoupper($this->table),' AS ') !==false){
									$this->table = str_ireplace(' AS ',' ',$this->table);
								}
								$this->conn = $this->connect->table($this->table);
							break;
							case 'laravel':
								$this->conn = $this->connect->table($this->table);
							break;
							case 'medoo':
								if(strpos(strtoupper($this->table),' AS ') !==false){
									$tableExp = explode(' ',$this->table);
									$this->table = str_ireplace(' AS ','',$this->table);
									$this->table=$tableExp[0].'('.end($tableExp).')';
								}
							break;
						}

					}
					break;
				case 'join':
					if($this->join){
						switch($this->engine){
							case 'think':
								foreach($this->join as $join){
									if(count($join)<3){
										throw new \InvalidArgumentException('SDB joins require a table and two columns.');
									}
									$joinTable = $join[0];
									if(\strpos(\strtoupper($joinTable),' AS ') !==false){
										$join[0] = \str_ireplace(' AS ',' ',$join[0]);
									}
									$joinOn =  $join[1].' = '.$join[2];
									$join[1] = $joinOn;
									unset($join[2]);
									$this->conn->join(... $join);
								}

							break;
							case 'laravel':
								$this->applyLaravelJoins();
								break;
							case 'medoo':
								foreach($this->join as $join){
									$op = '[><]';
									if(count($join)<3){
										throw new \InvalidArgumentException('SDB joins require a table and two columns.');
									}
									$joinOp = end($join);
									switch(\strtoupper($joinOp)){
										case 'LEFT':
											$op = '[>]';
										break;
										case 'RIGHT':
											$op = '[<]';
										break;
										case 'FULL':
											$op = '[<>]';
										break;
									}
									$joinTable = $op.$join[0];
									if(\strpos(\strtoupper($joinTable),' AS ') !==false){
										$tableExp = explode(' ',$joinTable);
										$joinTable = str_ireplace(' AS ','',$this->table);
										$joinTable = $tableExp[0].'('.end($tableExp).')';
									}
									$joinOn = explode('.',$join[1]);
									$this->medooJoin[$joinTable][$join[2]] = count($joinOn) > 1 ? $joinOn[1] : $join[1];
								}
							break;
						}
					}
					break;
				case 'bind':
					if($this->bind){
						switch($this->engine){
							case 'think':case 'laravel':
								foreach($this->bind as $exps){
									$exp = $exps[0];
									$bind = $exps[1];
									$this->conn->{$exp}(... $bind);
									if ($this->engine === 'laravel' && $this->connect->getDriverName() === 'mongodb'
										&& ($this->conn->joins !== null || $this->conn->lock !== null)) {
										throw new \InvalidArgumentException('MongoDB does not support delegated SQL joins or row locks.');
									}
								}

							break;
							case 'medoo':
								foreach($this->bind as $exps){
									$exp = $exps[0];
									$bind = $exps[1];
									$this->connect->{$exp}(... $bind);
								}
							break;
						}
					}
					break;
				case 'where':
					if($this->where){
						switch($this->engine){
							case 'think':
								$this->applyThinkWhere($this->conn);
							break;
							case 'laravel':
								$this->applyLaravelWhere($this->conn);
								break;
							case 'medoo':
								// Compiled once, without mutating the original query conditions.
							break;
						}
					}
					break;
				case 'whereExp':
					if($this->whereExp){
						$expMap = [
							'or'=>'whereOr',
							'wb'=>'whereBetween',
							'wnb'=>'whereNotBetween',
							'wi'=>'whereIn',
							'wni'=>'whereNotIn',
							'wl'=>'whereLike',
							'wnl'=>'whereNotLike',
							'we'=>'whereExists',
							'wne'=>'whereNotExists',
							'wn'=>'whereNull',
							'wnn'=>'whereNotNull',
							'wt'=>'whereTime',
							'wbt'=>'whereBetweenTime',
							'wnbt'=>'whereNotBetweenTime',
							'wc'=>'whereColumn'
						];
						switch($this->engine){
							case 'think':
								foreach($this->whereExp as $exps){
									$exp = $exps[0];
									$where = $exps[1];
									$method = $expMap[strtolower($exp)] ?? null;
									if ($method === null && in_array($exp, $expMap, true)) {
										$method = $exp;
									}
									if ($method === null) {
										throw new \InvalidArgumentException('Unsupported ThinkORM whereExp(): ' . $exp);
									}
									$this->conn->{$method}(...$where);
								}

							break;
							case 'medoo':
								// Compiled once, without mutating the original query conditions.
							break;
							case 'laravel':
								$this->applyLaravelWhereExp();
								break;
						}
					}
					break;
				case 'order':
					if($this->order){
						$order = $this->order;
						switch($this->engine){
							case 'think':
								if(is_array($order)){
									$new = [];
									foreach($order as $k=>$v){
										if(isset($order[$k]['f'])){
											$new[$order[$k]['f']] = $order[$k]['t'] ?? 'DESC';
										}else{
											$new[$v[0]] = $v[1] ?? 'DESC';
										}
									}
									$this->conn->order($new);
								}else{
									if($order=='rand()'){
										$this->conn->orderRand();
									}else{
										$this->conn->order($order);
									}

								}


							break;
							case 'laravel':
								$this->applyLaravelOrder();
								break;
							case 'medoo':
								if(is_array($order)){
									foreach($order as $k=>$v){
										if(isset($order[$k]['f'])){
											$this->medooWhere['ORDER'][$order[$k]['f']] = $order[$k]['t'] ?? 'DESC';
										}else{
											$this->medooWhere['ORDER'][$v[0]] = $v[1] ?? 'DESC';
										}
									}
								}else{
									if($order=='rand()'){
										$this->medooOp='rand';
									}else{
										$this->medooWhere['ORDER'][$order] = 'ASC';
									}

								}
							break;
						}

					}
					break;
				case 'limit':
					if($this->limit !== null){
						switch($this->engine){
							case 'think':
								if ($this->limit === 0) {
									$where = clone $this->conn;
									$this->conn->removeOption('where')->where(static function ($query) use ($where): void {
										$query->where($where);
									})->whereRaw('1=0');
								} elseif($this->offset){
									$this->conn->limit($this->offset,$this->limit);
								}else{
									$this->conn->limit($this->limit);
								}

							break;
							case 'laravel':
								$this->applyLaravelLimit();
								break;
							case 'medoo':
								if ($this->limit === 0) {
									$this->medooWhere[] = Medoo::raw('1=0');
								} elseif($this->offset){
									$this->medooWhere['LIMIT'] = [$this->offset,$this->limit];
								}else{
									$this->medooWhere['LIMIT'] = $this->limit;
								}

							break;
						}

					}
					break;
				case 'group':
					if($this->group){
						switch($this->engine){
							case 'think':
								$this->conn->group($this->group);
							break;
							case 'laravel':
								$this->conn->groupBy(...$this->laravelFields($this->group));
							break;
							case 'medoo':
								$this->medooWhere['GROUP'] = $this->group;
							break;
						}

					}
					break;
				case 'having':
					if($this->having){
						$having = $this->having;
						switch($this->engine){
							case 'think':
								$this->conn->having($having);
							break;
							case 'laravel':
								$this->conn->havingRaw($having);
							break;
							case 'medoo':
								if (is_array($having) || $having instanceof \Medoo\Raw) {
									$this->medooWhere['HAVING'] = $having;
								} elseif (preg_match('/^\s*([\p{L}_][\p{L}\p{N}_.]*)\s*(>=|<=|<>|!=|=|>|<)\s*(.*?)\s*$/uD', $having, $match)) {
									$operator = in_array($match[2], ['<>', '!='], true) ? '!' : $match[2];
									$value = is_numeric($match[3]) ? $match[3] + 0 : $match[3];
									$this->medooWhere['HAVING'] = [$match[1] . '[' . $operator . ']' => $value];
								} else {
									throw new \InvalidArgumentException('Use a condition array or Medoo::raw() for complex HAVING expressions.');
								}
							break;
						}
					}
					break;
				case 'lock':
					if($this->lock){

						switch($this->engine){
							case 'think':
								$this->conn->lock($this->lock);
							break;
							case 'laravel':
								$this->applyLaravelLock();
								break;
							case 'medoo':
								$this->medooWhere['LOCK'] = $this->lock;
							break;
						}

					}
					break;
			}
		}
	}

	/** Observe native PDO statements only in debug mode; never change another caller's SQL log. */
	private function currentDiagnosticStatement(): ?\PDOStatement {
		$class = $this->engine === 'think' ? \think\db\PDOConnection::class : \Medoo\Medoo::class;
		$property = self::$diagnosticStatementProperties[$class] ??= new \ReflectionProperty(
			$class, $this->engine === 'think' ? 'PDOStatement' : 'statement'
		);
		$statement = $property->getValue($this->connect);
		return $statement instanceof \PDOStatement ? $statement : null;
	}

	/** A weak reference distinguishes repeated identical SQL without retaining an old SQLite cursor. */
	private function debugStatementReference(): ?\WeakReference {
		$statement = $this->currentDiagnosticStatement();
		return $statement === null ? null : \WeakReference::create($statement);
	}

	/** Return only this operation's new statement, or null when native preparation/execution produced none. */
	private function finishDebugSql(): ?string {
		try {
			$statement = $this->currentDiagnosticStatement();
			if ($statement === null || $statement === $this->debugStatement?->get()) { return null; }
			return $this->engine === 'think' ? $this->connect->getLastSql() : $this->connect->last();
		} finally {
			$this->debugStatement = null;
		}
	}

	public function cm(){
		return $this->commit();
	}

	public function commit(){
		switch($this->engine){
			case 'think':
				return $this->connect->commit();
			break;
			case 'laravel':
				return $this->connect->commit();
			break;
			case 'medoo':
				$pdo = $this->connect->pdo;
				if ($pdo->commit() === false) {
					throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Could not commit the SDB transaction.');
				}
			break;
		}
	}

	public function rb(){
		return $this->rollback();
	}

	public function rollback(){
		switch($this->engine){
			case 'think':
				return $this->connect->rollback();
			break;
			case 'laravel':
				return $this->connect->rollback();
			break;
			case 'medoo':
				$pdo = $this->connect->pdo;
				if ($pdo->rollBack() === false) {
					throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Could not roll back the SDB transaction.');
				}
			break;
		}
	}

	public function lc($lock){ //锁表
		return $this->lock($lock);
	}
	/** AutoForm uses the transaction itself on SQLite, which has no row-level SELECT locks. */
	public function supportsRowLocks(): bool {
		$driver = $this->engine === 'laravel' ? $this->connect->getDriverName() : $this->type;
		return !in_array($driver, ['sqlite', 'mongodb'], true);
	}
	public function lock($lock){ //锁表
		$this->lock = $lock;
		return $this;
	}

	public function st(){
		return $this->startTrans();
	}
	public function startTrans(){
		switch($this->engine){
			case 'think':
				$this->connect->startTrans();
			break;
			case 'laravel':
				$this->connect->beginTransaction();
			break;
			case 'medoo':
				$pdo = $this->connect->pdo;
				if ($pdo->beginTransaction() === false) {
					throw \RC\Helper\Db\PdoFailure::fromPdo($pdo, 'Could not begin the SDB transaction.');
				}
			break;

		}
	}

	public function d(){
		return $this->delete();
	}

	public function delete(){
		if ($this->engine === 'laravel') { $this->laravelReadOperation = false; }
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->delete();
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				} finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				} 
			break;
			case 'laravel':
				return $this->executeLaravel(fn() => $this->conn->delete() !== false);
			break;
			case 'medoo':
				try {
				    $where = $this->medooBuildWhere();
					$result = $this->connect->delete($this->table,$where);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
		}
	}

	/** Apply SDB boolean groups to ThinkORM without mutating reusable query conditions. */
	private function applyThinkWhere($query): void {
		$and = $or = [];
		foreach ($this->where as $condition) {
			$boolean = 'AND';
			if (is_array($condition) && isset($condition['raw'])) {
				$boolean = $condition['boolean'];
			} elseif (is_array($condition)) {
				$size = count($condition);
				if ($size === 4 || ($size === 2 && !is_string($condition[0]) && is_callable($condition[0]))) {
					$boolean = array_pop($condition);
				} elseif ($size === 3 && is_string($condition[2]) && in_array(strtoupper($condition[2]), ['AND', 'OR'], true)
					&& !(is_string($condition[1]) && in_array(strtolower($condition[1]), ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'not like', 'regexp', 'not regexp'], true))) {
					$boolean = array_pop($condition);
				}
			}
			$boolean = strtoupper($boolean);
			if (!in_array($boolean, ['AND', 'OR'], true)) {
				throw new \InvalidArgumentException('Invalid WHERE boolean connector.');
			}
			if ($boolean === 'OR') {
				$or[] = $condition;
			} else {
				$and[] = $condition;
			}
		}
		if (count($or) === 1 && $and) {
			$query->where(function ($group) use ($and, $or): void {
				$group->where(function ($inner) use ($and): void {
					foreach ($and as $condition) {
						$this->applyThinkCondition($inner, $condition);
					}
				});
				$this->applyThinkCondition($group, $or[0], 'OR');
			});
			return;
		}
		foreach ($and as $condition) {
			$this->applyThinkCondition($query, $condition);
		}
		if ($or) {
			$query->where(function ($group) use ($or): void {
				foreach ($or as $condition) {
					$this->applyThinkCondition($group, $condition, 'OR');
				}
			});
		}
	}

	/** Translate one condition or SDB closure into an upstream ThinkORM query. */
	private function applyThinkCondition($query, string|array $condition, string $boolean = 'AND'): void {
		if (is_string($condition) || isset($condition['raw'])) {
			$sql = is_string($condition) ? $condition : $condition['raw'];
			if (trim($sql) === '') {
				throw new \InvalidArgumentException('Empty raw WHERE conditions are not allowed.');
			}
			$bindings = [];
			foreach (is_string($condition) ? [] : $condition['bindings'] as $name => $value) {
				if (is_string($name) && str_starts_with($name, ':')) {
					$name = substr($name, 1);
					if (is_numeric($name)) { throw new \InvalidArgumentException('ThinkORM named SQL parameters cannot be numeric.'); }
				}
				if ($name === '' || array_key_exists($name, $bindings)) {
					throw new \InvalidArgumentException('Empty or duplicate ThinkORM SQL parameter.');
				}
				$bindings[$name] = $value;
			}
			$query->whereRaw($sql, $bindings, $boolean);
			return;
		}
		$method = $boolean === 'OR' ? 'whereOr' : 'where';
		if (!is_string($condition[0]) && is_callable($condition[0])) {
			$query->{$method}(function ($inner) use ($condition): void {
				$group = clone $this;
				$group->where = $group->whereExp = $group->bind = null;
				$group->conn = $inner;
				$condition[0]($group);
				if (!$group->where && !$group->whereExp && !$group->bind) {
					throw new \InvalidArgumentException('Empty WHERE closure groups are not allowed.');
				}
				$group->build(['where', 'whereExp', 'bind']);
			});
			return;
		}
		if (count($condition) < 2 || count($condition) > 3 || !is_string($condition[0])) {
			throw new \InvalidArgumentException('Invalid SDB condition.');
		}
		if (count($condition) === 3) {
			$aliases = ['bt' => 'between', 'wb' => 'between', 'nbt' => 'not between', 'wnb' => 'not between',
				'wi' => 'in', 'wni' => 'not in', 'notin' => 'not in', 'wl' => 'like', 'wnl' => 'not like',
				'null' => 'null', 'notnull' => 'not null', 'wn' => 'null', 'wnn' => 'not null'];
			$condition[1] = $aliases[strtolower($condition[1])] ?? $condition[1];
		}
		$query->{$method}(...$condition);
	}

	/** Build immutable Medoo predicates from SDB conditions, including nested closures. */
	private function compileMedooConditions(): array {
		$conditions = $this->where ?? [];
		$aliases = [
			'wb' => 'between', 'wherebetween' => 'between',
			'wnb' => 'not between', 'wherenotbetween' => 'not between',
			'wi' => 'in', 'wherein' => 'in', 'wni' => 'not in', 'wherenotin' => 'not in',
			'wl' => 'like', 'wherelike' => 'like', 'wnl' => 'not like', 'wherenotlike' => 'not like',
			'wn' => 'null', 'wherenull' => 'null', 'wnn' => 'not null', 'wherenotnull' => 'not null',
			'regexp' => 'regexp', 'notregexp' => 'not regexp',
		];
		foreach ($this->whereExp ?? [] as [$expression, $args]) {
			$operator = $aliases[strtolower($expression)] ?? null;
			if ($operator === null || !isset($args[0])) {
				throw new \InvalidArgumentException('Unsupported Medoo whereExp(): ' . $expression);
			}
			$isNull = in_array($operator, ['null', 'not null'], true);
			$conditions[] = [$args[0], $operator, $isNull ? null : ($args[1] ?? null), $args[$isNull ? 1 : 2] ?? 'AND'];
		}
		$and = $or = [];
		foreach ($conditions as $index => $condition) {
			$boolean = 'AND';
			if (is_string($condition)) {
				if (trim($condition) === '') {
					throw new \InvalidArgumentException('Empty raw WHERE conditions are not allowed.');
				}
				$node = [Medoo::raw($condition)];
			} elseif ($condition[0] instanceof \Medoo\Raw) {
				$node = [$condition[0]];
				$boolean = $condition[1];
			} elseif (!is_string($condition[0]) && is_callable($condition[0])) {
				$group = clone $this;
				$group->where = $group->whereExp = null;
				$condition[0]($group);
				$node = $group->compileMedooConditions();
				if ($node === []) {
					throw new \InvalidArgumentException('Empty WHERE closure groups are not allowed.');
				}
				$boolean = $condition[1];
			} else {
				$count = count($condition);
				if ($count < 2 || $count > 4 || !is_string($condition[0])) {
					throw new \InvalidArgumentException('Invalid SDB condition.');
				}
				$column = $condition[0];
				$this->connect->columnQuote($column);
				$operator = '=';
				$value = $condition[1];
					if ($count === 3 && is_string($condition[2]) && in_array(strtoupper($condition[2]), ['AND', 'OR'], true)
						&& !(is_string($condition[1]) && in_array(strtolower($condition[1]), ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'not like', 'regexp', 'not regexp'], true))) {
					$boolean = $condition[2];
				} elseif ($count >= 3) {
					$operator = strtolower(trim((string)$condition[1]));
					$value = $condition[2];
					$boolean = $condition[3] ?? 'AND';
				}
				$operators = [
					'=' => '', '' => '', '!=' => '!', '<>' => '!', '!' => '!',
					'>' => '>', '>=' => '>=', '<' => '<', '<=' => '<=',
					'between' => '<>', 'bt' => '<>', 'wb' => '<>',
					'not between' => '><', 'notbetween' => '><', 'nbt' => '><', 'wnb' => '><', '><' => '><',
					'in' => '', 'wi' => '', 'not in' => '!', 'notin' => '!', 'ni' => '!', 'wni' => '!',
					'like' => '~', 'l' => '~', 'wl' => '~', '~' => '~',
					'not like' => '!~', 'notlike' => '!~', 'nl' => '!~', 'wnl' => '!~', '!~' => '!~',
					'null' => '', 'isnull' => '', 'is null' => '', 'n' => '', 'wn' => '',
					'notnull' => '!', 'isnotnull' => '!', 'not null' => '!', 'is not null' => '!', 'nn' => '!', 'wnn' => '!',
					'regexp' => 'REGEXP', 'not regexp' => '!REGEXP', '!regexp' => '!REGEXP',
				];
				if (!array_key_exists($operator, $operators)) {
					throw new \InvalidArgumentException('Unsupported Medoo comparison: ' . $operator);
				}
				$mapped = $operators[$operator];
				if (in_array($operator, ['null', 'isnull', 'is null', 'n', 'wn', 'notnull', 'isnotnull', 'not null', 'is not null', 'nn', 'wnn'], true)) {
					$value = null;
				}
				if (in_array($mapped, ['<>', '><'], true) || in_array($operator, ['in', 'wi', 'not in', 'notin', 'ni', 'wni'], true)) {
					if (!is_array($value)) {
						if (!is_string($value)) {
							throw new \InvalidArgumentException('IN/BETWEEN values must be an array or a comma-separated string.');
						}
						$value = $value === '' ? [] : array_map('trim', explode(',', $value));
					}
					if (in_array($mapped, ['<>', '><'], true) && count($value) !== 2) {
						throw new \InvalidArgumentException('BETWEEN requires exactly two values.');
					}
				}
				$node = [$column . ($mapped !== '' ? '[' . $mapped . ']' : '') => $value];
			}
			$boolean = strtoupper($boolean);
			if (!in_array($boolean, ['AND', 'OR'], true)) {
				throw new \InvalidArgumentException('Invalid WHERE boolean connector.');
			}
			if ($boolean === 'OR') {
				if (count($node) === 1 && is_string(array_key_first($node)) && !is_array(reset($node))) {
					$key = array_key_first($node);
					$or[array_key_exists($key, $or) ? $key . ' #sdb_' . $index : $key] = reset($node);
				} else {
					$or['AND #sdb_' . $index] = $node;
				}
			} else {
				if (count($node) === 1 && is_string(array_key_first($node)) && !is_array(reset($node))) {
					$key = array_key_first($node);
					$and[array_key_exists($key, $and) ? $key . ' #sdb_' . $index : $key] = reset($node);
				} else {
					$and['AND #sdb_' . $index] = $node;
				}
			}
		}
		// Retain SDB's documented OR-group semantics rather than SQL operator precedence.
		if (count($or) === 1 && $and) {
			$or['AND #sdb_outer'] = $and;
			return ['OR' => $or];
		}
		if ($or) {
			$and['OR #sdb_outer'] = $or;
		}
		return $and;
	}

	/** Accept SDB field strings or Medoo typed/nested/raw column arrays. */
	protected function medooParseFields($field): mixed {
		if (is_array($field)) {
			return $field;
		}
		if ($field === null || $field === '' || $field === '*') {
			return '*';
		}
		$columns = [];
		$identifier = '[\p{L}_][\p{L}\p{N}_]*(?:\.[\p{L}_][\p{L}\p{N}_]*)?';
		foreach (explode(',', $field) as $column) {
			$column = trim($column);
			if (strpbrk($column, "( \t") === false) {
				$columns[] = $column;
				continue;
			}
			if (preg_match('/^(sum|min|max|count|avg)\(\s*(DISTINCT\s+)?(' . $identifier . '|\*)\s*\)(?:\s+(?:AS\s+)?(' . $identifier . '))?$/iuD', $column, $match)) {
				$source = $match[3] === '*' ? '*' : $this->connect->columnQuote($match[3]);
				$alias = $match[4] ?? ($match[3] === '*' ? strtolower($match[1]) : $match[3]);
				$columns[$alias] = Medoo::raw(strtoupper($match[1]) . '(' . ($match[2] ?? '') . $source . ')');
			} elseif (preg_match('/^(' . $identifier . ')\s+(?:AS\s+)?(' . $identifier . ')$/iuD', $column, $match)) {
				$columns[] = $match[1] . '(' . $match[2] . ')';
			} else {
				$columns[] = $column;
			}
		}
		return $columns;
	}

	/** Rebuild raw extras for each statement, including pagination's count query. */
	protected function medooBuildWhere(?array $clauses = null) {
		$clauses = $clauses ?? $this->medooWhere ?? [];
		$this->connect->buildRCWhere([]);
		if ($this->medooWhereRaw) {
			$this->connect->buildRCWhere($clauses);
			return Medoo::raw('WHERE (' . $this->medooWhereRaw . ')');
		}
		return $clauses;
	}
	public function f($field='*'){
		return $this->find($field);
	}
	public function find($field='*'){
		if ($this->engine === 'laravel') { $this->laravelReadOperation = true; }
		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		if ($this->limit === 0) { $this->sql = null; return null; }
		switch($this->engine){
			case 'think':
				try {
					if($this->where===null && $this->whereExp===null){
					       $this->conn->whereRaw('1=1');
				    }
					$result = $this->conn->field($field)->find();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
			case 'laravel':
				return $this->executeLaravel(fn() => $this->laravelRow($this->conn->first($this->laravelFields($field))));
			break;
			case 'medoo':
				$field = $this->medooParseFields($field);
				try {
					if($this->medooOp=='rand' || $this->medooWhereRaw){
						if(isset($this->medooWhere['LIMIT'])){
							if(is_array($this->medooWhere['LIMIT'])){
								$this->medooWhere['LIMIT'][1] = 1;
							}
						}else{
							$this->medooWhere['LIMIT'] = 1;
						}
					}
					$where = $this->medooBuildWhere();
					if($this->medooJoin){
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$this->medooJoin ?? null,$field,$where) : $this->connect->get($this->table,$this->medooJoin ?? null,$field,$where);
					}else{
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$field,$where) : $this->connect->get($this->table,$field,$where);
					}
					return $this->medooOp=='rand' ? ($result[0] ?? null) : $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
		}
	}

	public function a(array $data, ?string $primaryKey = null){
		return $this->add($data, $primaryKey);
	}
	/** Insert rows; pass the primary key for PostgreSQL RETURNING, e.g. add($data, 'id'). */
	public function add(array $data, ?string $primaryKey = null){
		$this->insertid = null;
		if ($this->engine === 'laravel') { $this->laravelReadOperation = false; }
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					if ($primaryKey !== null) {
						$this->conn->pk($primaryKey);
					}
					$result = $this->conn->insert($data);
					$this->insertid = $this->conn->getLastInsID();
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				} finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				} 
			break;
			case 'laravel':
				$this->insertid = null;
				return $this->executeLaravel(function () use ($data, $primaryKey) {
					if (array_is_list($data) && isset($data[0]) && is_array($data[0])) { return $this->conn->insert($data); }
					$this->insertid = $this->conn->insertGetId($data, $primaryKey);
					return true;
				});
			break;
			case 'medoo':
				try {
					$result = $this->connect->insert($this->table,$data,$primaryKey);
					$this->insertid = $this->connect->id();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
		}
	}

	public function u(array $data){
		return $this->update($data);
	}
	public function update(array $data){
		if ($this->engine === 'laravel') { $this->laravelReadOperation = false; }
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->update($data);
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				} 
			break;
			case 'laravel':
				return $this->executeLaravel(fn() => $this->conn->update($data) !== false);
			break;
			case 'medoo':
				try {
				    $where = $this->medooBuildWhere();
					$result = $this->connect->update($this->table,$data,$where);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
		}
	}

	public function p($field='*',$listRows = null,$simple = false,$method = 'get'){
		return $this->paginate($field,$listRows,$simple,$method);
	}
	public function paginate($field='*',$listRows = null,$simple = false,$method = 'get'){
		if ($this->engine === 'laravel') { $this->laravelReadOperation = true; }
		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				$this->conn->field($field);

				if (is_int($simple)) {
		            $total  = $simple;
		            $simple = false;
		        }
		        $defaultConfig = [
		            'query'     => [], //url额外参数
		            'fragment'  => '', //url锚点
		            'var_page'  => 'page', //分页变量
		            'list_rows' => 15, //每页数量
		        ];

		        if (is_array($listRows)) {
		            $config   = array_merge($defaultConfig, $listRows);
		            $listRows = intval($config['list_rows']);
		        } else {
		            $config   = $defaultConfig;
		            $listRows = intval($listRows ?: $config['list_rows']);
		            $config['list_rows'] = $listRows;
		        }
				$page = isset($config['page']) ? (int) $config['page'] : (int) $this->request->{$method}($config['var_page']);
		        $page = $page < 1 ? 1 : $page;
		        $config['page'] = $page;
				if ($listRows < 1) {
					throw new \InvalidArgumentException('Pagination page size must be positive.');
				}
				try {
					$result = $this->conn->paginate($config,$total ?? $simple);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				} 
				return $result;
			break;
			case 'laravel':
				return $this->executeLaravel(fn() => $this->paginateLaravel($field, $listRows, $simple, $method));
			break;
			case 'medoo':
				try {
					$field = $this->medooParseFields($field);
					$config = is_array($listRows) ? $listRows : [];
					$listRows = (int)($config['list_rows'] ?? $listRows ?? 15);
					if ($listRows < 1) {
						throw new \InvalidArgumentException('Pagination list_rows must be greater than zero.');
					}
					$page = max(1, (int)($config['page'] ?? $this->request->{$method}($config['var_page'] ?? 'page')));
					$path = $config['path'] ?? '/?page=[PAGE]';
					$total = is_int($simple) ? $simple : null;
					if (is_int($simple)) {
						$simple = false;
					}
					if ($total === null && !$simple) {
						$countClauses = $this->medooWhere ?? [];
						unset($countClauses['LIMIT'], $countClauses['ORDER'], $countClauses['LOCK']);
						$countWhere = $this->medooBuildWhere($countClauses);
						if ($this->group) {
							$total = $this->connect->countSelection($this->table, $field, $countWhere, $this->medooJoin);
						} elseif ($this->medooJoin) {
							$total = $this->connect->count($this->table, $this->medooJoin, '*', $countWhere);
						} else {
							$total = $this->connect->count($this->table, '*', $countWhere);
						}
					}
					$this->medooWhere['LIMIT'] = [($page - 1) * $listRows, $simple ? $listRows + 1 : $listRows];
					$where = $this->medooBuildWhere();
					$result = $this->medooJoin
						? $this->connect->select($this->table, $this->medooJoin, $field, $where)
						: $this->connect->select($this->table, $field, $where);
					$hasMore = $simple ? count($result) > $listRows : null;
					if ($simple) {
						$result = array_slice($result, 0, $listRows);
					}
					return new Paginator($total, $listRows, $page, $path, $result, $hasMore);
				} catch (\Throwable $ex) {
					$this->error = $ex->getMessage();
					return false;
				} finally {
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
		}
	}

	public function s($field='*'){
		return $this->select($field);
	}
	public function select($field='*'){
		if ($this->engine === 'laravel') { $this->laravelReadOperation = true; }
		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		if ($this->limit === 0) { $this->sql = null; return []; }
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->field($field)->select();
						if($result === null){
							return null;
						}
						return \is_object($result) && method_exists($result, 'toArray') ? $result->toArray() : $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				} 
			break;
			case 'laravel':
				return $this->executeLaravel(fn() => $this->laravelRows($this->conn->get($this->laravelFields($field))));
			break;
			case 'medoo':
				$field = $this->medooParseFields($field);
				
				$where = $this->medooBuildWhere();
				try {
					if($this->medooJoin){
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$this->medooJoin ?? null,$field,$where) : $this->connect->select($this->table,$this->medooJoin ?? null,$field,$where);
					}else{
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$field,$where) : $this->connect->select($this->table,$field,$where);
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
		}
	}

	public function c($field='*'){
		return $this->count($field);
	}
	public function count($field='*'){
		if ($this->engine === 'laravel') { $this->laravelReadOperation = true; }
		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		if ($this->limit === 0) { $this->sql = null; return 0; }
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->count($field);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				} 
			break;
			case 'laravel':
				return $this->executeLaravel(fn() => $this->group
					? $this->laravelPaginationCount($this->laravelFields($this->group))
					: $this->conn->count($this->laravelFields($field)));
			break;
			case 'medoo':
				try {
					$where = $this->medooBuildWhere();
					if ($this->group) {
						$result = $this->connect->countSelection($this->table, ['__sdb_row' => Medoo::raw('1')], $where, $this->medooJoin);
					} elseif ($this->medooJoin) {
						$result = $this->connect->count($this->table, $this->medooJoin, $field, $where);
					} else {
						$result = $this->connect->count($this->table, $field, $where);
					}
					return $result;
				} catch (\Throwable $ex) {
					$this->error = $ex->getMessage();
					return false;
				} finally {
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
		}
	}

	public function agg($fun,$field){
		if ($this->engine === 'laravel') { $this->laravelReadOperation = true; }
		$fun = is_string($fun) ? strtolower($fun) : '';
		if(!$field || !in_array($fun,['max','min','avg','sum'], true)){
			$this->error = $this->sql = null;
			return null;
		}

		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->{$fun}($field);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				} 
			break;
			case 'laravel':
				return $this->executeLaravel(fn() => $this->conn->{$fun}($field));
			break;
			case 'medoo':
				try {
				    $where = $this->medooBuildWhere();
					if($this->medooJoin){
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$this->medooJoin ?? null,$field,$where) : $this->connect->{$fun}($this->table,$this->medooJoin ?? null,$field,$where);
					}else{
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$field,$where) : $this->connect->{$fun}($this->table,$field,$where);
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if ($this->enbleDebug) { $this->sql = $this->finishDebugSql(); }
				}
			break;
		}
	}
}
?>
