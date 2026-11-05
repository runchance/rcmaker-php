<?php
namespace RC\Helper;
use RC\Helper\Validator;

class AutoForm{
	//操作类型 add添加 update更新 del删除 toggle字段值切换 list获取数据列表 get获取一行数据
	protected static $types = array("add","update","toggle","delete","list","paginate","get");
	protected static $msg = array(
		"add_success"=>"数据添加成功",
		"add_failed"=>"数据添加失败",
		"update_success"=>"数据修改成功",
		"update_failed"=>"数据修改失败",
		"delete_failed"=>"数据删除失败",
		"get_list_success"=>"获取数据成功",
		"get_list_sfailed"=>"获取数据失败",
		"delete_success"=>"删除成功",
		"delete_failed"=>"删除失败",
		"type_error"=>"操作类型错误",
		"obj_name_is_empty"=>"操作对象名称不能为空",
		"data_is_empty"=>"传入参数不能为空",
		"table_is_empty"=>"数据表不能为空",
		"check_config_error"=>"检测器配置错误",
		"range_failed"=>"取值范围错误",
		"post_not_exist"=>"传值不存在",
		"method_error"=>"参数传递方法不被支持",
		"is_not_exist"=>"记录不存在",
		"is_repeat"=>"记录已经存在,不允许重复",
		"index_is_null"=>"[index]索引设置不能为空",
	);
	protected static $methods = ['post','get']; 
	protected $toggle = null;
	public $db = null;
	public $id = null; 
	protected $before = null;
	protected $after = null;
	protected $query = null;
	protected $type = null;
	protected $rule = null;
	protected $index = ''; 
	protected $name = ''; 
	protected $auto = true; 
	protected $trans = false;
	private bool $transactionActive = false;
	protected $table = ''; 
	protected $limit = 15;
	protected $group = null;
	protected $page = [];
	protected $par = null;
	protected $check = null;
	protected $order = [];
	protected $validator = null;
	protected $method = 'post';
	protected $transferData = []; //通过GET或者POST传递的元数据
	protected $data = []; //原始数据
	protected $_data = []; //传入数据
	protected $_setData = []; //人工设置的数据
	protected $tabledata = []; //数据库读出数据
	protected $where = [];
	protected $whereExp = [];
	protected $fields = '*';
	protected $request = null;
	public function __construct($request,$vars = array()){ //初始化参数
		$this->request = $request;
		$this->db = $vars['db'] ?? $request->simple_database()->debug();
		$this->index = $vars['index'] ?? '';
		$this->id = $vars['id'] ?? null;
		$this->name = $vars['name'] ?? '';
		$this->auto = $vars['auto'] ?? true;
		$this->table = $vars['table'] ?? '';
		$this->limit = $vars['limit'] ?? 15;
		$this->order = $vars['order'] ?? '';
		$this->page = $vars['page'] ?? [];
		$this->trans = $vars['trans'] ?? false; //是否全程开启事务
		$this->transferData = $vars['transferData'] ?? [];
		$this->method = $vars['method'] ?? "post";
		$this->type = $vars['type'] ?? null;
		$this->data = $vars['data'] ?? null;
		$this->check = $vars['check'] ?? null;
		$this->group = $vars['group'] ?? null;
		$this->fields = $vars['fields'] ?? $this->fields;
		$this->where = $vars['where'] ?? [];
		$this->whereExp = $vars['whereExp'] ?? [];
		$this->query = $vars['query'] ?? null;
		$this->validator = new Validator();
		$data = $this->data;
		foreach(($data ?? []) as $key=>$rules){
			$transferkey = $data[$key]['key'] ?? $key;
			$method = $data[$key]['method'] ?? $this->method;
			if(!in_array($method,static::$methods)){
				throw new \Exception((string)static::$msg['method_error'] ?? null);
			}
			if(is_array($rules)){
				$this->_data[$key] = array_key_exists($transferkey, $this->transferData) ? $this->transferData[$transferkey] : $this->request->{$method}($transferkey);
				
				$this->rule[$key] = $rules;
				unset($this->rule[$key]['key'], $this->rule[$key]['method']);
				if($this->type=='list' || $this->type=='paginate'){
					$this->rule[$key]['required'] = false;
				}
			}else{
				$this->_data[$rules] = null;
			}
			
		}
		
	}

	public function getTableData(){
		return $this->tabledata;
	}

	public function setData($key,$value){
		$this->_setData[$key] = $value;
		return true;
	}

	public function where(...$where){
		$this->where = array_merge($this->where,$where);
		return $this;
	}

	public function whereExp(...$whereExp){
		$this->whereExp = array_merge($this->whereExp,$whereExp);
		return $this;

	}


	public function data($key=''){
		return $key ? $this->_data[$key] : $this->_data;
	}

	public function before($handle = null){
		$this->before = $handle;
	}

	public function after($handle = null){
		$this->after = $handle;
	}

	public function getToggle(){
		return $this->toggle;
	}

	protected function hasIndexScope(): bool{
		return $this->index !== '' && $this->id !== null && $this->id !== '';
	}

	protected function resolveScope(): array{
		$where = $this->where ?: null;
		$whereExp = $this->whereExp ?: null;
		if(!$where && !$whereExp && $this->hasIndexScope()){
			if(is_array($this->id)){
				$whereExp = ['wi',$this->index,$this->id];
			}else{
				$where = [$this->index,'=',$this->id];
			}
		}
		return [$where,$whereExp];
	}

	protected function applyScope($base, $where = null, $whereExp = null){
		return self::applyStoredScope($base, $where, $whereExp);
	}

	/** Reuse configured SDB conditions inside a static row guard without retaining this form in its query. */
	private static function applyStoredScope($base, $where = null, $whereExp = null){
		if($where){
			if(isset($where[0]) && is_array($where[0])){
				foreach($where as $w){
					$base->where(... array_values($w));
				}
			}else{
				$base->where(... array_values($where));
			}
		}
		if($whereExp){
			if(isset($whereExp[0]) && is_array($whereExp[0])){
				foreach($whereExp as $w){
					$base->whereExp(... array_values($w));
				}
			}else{
				$base->whereExp(... array_values($whereExp));
			}
		}
		return $base;
	}

	protected function loadToggleData($where = null, $whereExp = null){
		if(!$where && !$whereExp){
			throw new \Exception((string)static::$msg['range_failed'] ?? null);
		}
		$base = $this->applyScope($this->db->table($this->table), $where, $whereExp)->lock($this->trans && $this->db->supportsRowLocks());
		$singleIndex = $this->hasIndexScope() && !is_array($this->id) && !$this->where && !$this->whereExp;
		$tabledata = $singleIndex ? $base->find($this->fields) : $base->select($this->fields);
		$this->assertQuerySucceeded($tabledata);
		if($tabledata !== null && !is_array($tabledata)){
			$tabledata = $tabledata->toArray();
		}
		if(!$tabledata){
			throw new \Exception('['.$this->name.']'.(static::$msg['is_not_exist'] ?? null));
		}
		return $tabledata;
	}

	/** Do not treat a failed lookup as a missing record or a successful uniqueness check. */
	protected function assertQuerySucceeded($result): void {
		if ($result === false) {
			throw new \RuntimeException('Database query failed: ' . $this->db->error());
		}
	}

	/** Roll back only the transaction opened by this form; never retry an uncertain rollback or replace the original error. */
	private function rollbackOnFailure(): void {
		if($this->trans && $this->transactionActive){
			try{
				$this->rollback();
			}catch(\Throwable){}
		}
	}

	public function handle($callback = null){
		if($this->transactionActive){
			throw new \LogicException('Commit or roll back the active AutoForm transaction before calling handle() again.');
		}
		if(!$this->name){ //判定名操作对象名称是否存在
			throw new \Exception((string)static::$msg['obj_name_is_empty'] ?? null);
		}
		if(!$this->table){
			throw new \Exception((string)static::$msg['table_is_empty'] ?? null);
		}
		if(!in_array($this->type,static::$types)){ //检查操作类型是否合法
			throw new \Exception((string)static::$msg['type_error'] ?? null);
		}

		if(!$this->data && ($this->type=='add' || $this->type=='update' || $this->type=='toggle')){
			throw new \Exception((string)static::$msg['data_is_empty'] ?? null);
		}

		
		if($this->rule){
			try{
				$this->_data = array_merge($this->validator->input($this->_data,$this->rule),$this->_setData);
			}catch(\Throwable $ex){
				throw new \Exception((string)$ex->getMessage());
			}
		}
		if($this->trans){
			$this->db->startTrans();
			$this->transactionActive = true;
		}

		try{
			if(is_callable($this->before)){
				$handle = $this->before;
				$handle();
			}

			// An explicit toggle scope takes precedence over id, so do not preload or lock unrelated index rows.
			if($this->hasIndexScope() && ($this->type !== 'toggle' || (!$this->where && !$this->whereExp))){
				$check = [$this->index,'exist',null,$this->id];
				$this->check($check);
				if($this->type === 'toggle' && !$this->where && !$this->whereExp && !$this->check){
					// Only this invocation's exact index lookup is safe to reuse; other checks may load unrelated rows.
					$toggleData = $this->tabledata;
				}
			}

			if($this->check){
				$this->check(...$this->check);
			}

			switch($this->type){
				case 'list': case 'paginate':
					$queryKeys = [];
					foreach(($this->query ?? []) as $query){
						$queryKey = $query[0] ?? null;
						$queryWhere = $query[1] ?? null;
						$tableKey = $query[2] ?? $queryKey;
						$queryFun = $query[3] ?? null;
						$data = $this->_data[$queryKey] ?? null;
						if(isset($data) && $queryFun && is_callable($queryFun)){
							$data = $queryFun($data);
						}
						if(isset($data)){
							$queryKeys[$queryKey] = true;
							if($queryWhere){
								switch($queryWhere){
									case '=':case 'eq':
										$this->where([$tableKey,'=',$data]);
									break;
									case '>':case '>=':case '<':case '<=':
										$this->where([$tableKey,$query[1],$data]);
									break;
									case 'like':
										$this->where([$tableKey,'like',"%$data%"]);
									break;
									case 'like%':
										$this->where([$tableKey,'like',"$data%"]);
									break;
									case '%like':
										$this->where([$tableKey,'like',"%$data"]);
									break;
									case 'in':
										$this->whereExp(['wi',$tableKey,$data]);
									break;
								}	
							}
						}
					}

					foreach($this->_data as $key=>$data){
						if(!isset($queryKeys[$key])){
							$this->where([$key,'=',$data]);
						}
					}

					$this->getList($this->type=='paginate');
					return $this->getTableData();
				break;
				case 'get':
					[$where, $whereExp] = $this->resolveScope();
					$this->getData($where, $whereExp);
					return $this->getTableData();
				break;
				case 'delete':
					[$where, $whereExp] = $this->resolveScope();
					$this->delete(['table'=>$this->table,'where'=>$where,'whereExp'=>$whereExp]);
				break;
				case 'add':
					$this->add(['table'=>$this->table,'data'=>$this->_data]);
					$this->id = $this->db->id();
				break;
				case 'update':
					[$where, $whereExp] = $this->resolveScope();
					$this->update(['table'=>$this->table,'data'=>$this->_data,'where'=>$where,'whereExp'=>$whereExp]);
				break;
				case 'toggle':
					[$where, $whereExp] = $this->resolveScope();
					$this->toggle = null;
					$tableData = $toggleData ?? $this->loadToggleData($where, $whereExp);
					$this->tabledata = $tableData;
					$updator = [];
					foreach($this->_data as $key=>$val){
						if(isset($tableData[0]) && is_array($tableData[0])){
							foreach($tableData as $k=>$data){
								$toggle = (int)$tableData[$k][$key]==0 ? 1 : 0;
								if($this->index && isset($tableData[$k][$this->index])){
									$rowId = $tableData[$k][$this->index];
									$rowWhere = [$this->index,'=',$rowId];
									if($this->where || $this->whereExp){
										// Keep OR predicates grouped before intersecting them with this row's index.
										$rowWhere = [[static function($query) use ($where, $whereExp): void {
											self::applyStoredScope($query, $where, $whereExp);
										}], $rowWhere];
									}
									$toggleKey = $rowId instanceof \Stringable ? (string) $rowId : $rowId;
									$this->toggle[$toggleKey][$key] = $toggle;
								}else{
									throw new \Exception((string)static::$msg['index_is_null'] ?? null);
								}
								$updator[] = ['where'=>$rowWhere,'whereExp'=>null,'table'=>$this->table,'data'=>[$key=>$toggle]];
							}
						}else{
							if(isset($tableData[$key])){
								$toggle = (int)$tableData[$key]==0 ? 1 : 0;
								$this->toggle[$key] = $toggle;
								$updator[] = ['where'=>$where,'whereExp'=>$whereExp,'table'=>$this->table,'data'=>[$key=>$toggle]];
							}
						}
					}
					$this->update(... array_values($updator));
				break;
			}
			if(is_callable($callback)){
				$callback($this->id);
			}else{
				if(is_callable($this->after)){
					$handle = $this->after;
					$handle($this->id);
				}
			}

			return true;
		}catch(\Throwable $ex){
			$this->rollbackOnFailure();
			throw $ex;
		}
	}

	public function commit(){
		if($this->trans && !$this->transactionActive){
			throw new \LogicException('This AutoForm does not have an active transaction to commit.');
		}
		// Relinquish automatic cleanup before COMMIT: a lost acknowledgement must not roll back an outer transaction.
		$this->transactionActive = false;
		$this->db->commit();
		return true;
	}

	public function rollback(){
		if($this->trans && !$this->transactionActive){
			return true;
		}
		$this->transactionActive = false;
		$this->db->rollback();
		return true;
	}

	public function getList($paginate = true){
		$table = $this->table ?? null;
		$where = $this->where ?? null;
		$whereExp = $this->whereExp ?? null;
		$base = $this->db->table($table);
		$order = $this->order;
		if($this->group){
			$base->group($this->group);
		}
		if($where || $whereExp){
			if($where){
				if(isset($where[0]) && is_array($where[0])){
					foreach($where as $w){
						$base->where(... array_values($w));
					}
				}else{
					$base->where(... array_values($where));
				}
			}
			if($whereExp){
				if(isset($whereExp[0]) && is_array($whereExp[0])){
					foreach($whereExp as $w){
						$base->whereExp(... array_values($w));
					}
				}else{
					$base->whereExp(... array_values($whereExp));
				}
			}

			$this->tabledata = $paginate ? $base->order($order)->paginate($this->fields,$this->page['listRows'] ?? $this->limit,$this->page['simple'] ?? false,$this->method) : $base->limit($this->limit)->order($order)->select($this->fields);	
		}else{
			$this->tabledata = $paginate ? $base->order($order)->paginate($this->fields,$this->page['listRows'] ?? $this->limit,$this->page['simple'] ?? false,$this->method) : $base->limit($this->limit)->order($order)->select($this->fields);
		}
		$this->assertQuerySucceeded($this->tabledata);
		return true;
	}

	public function getData($where = null, $whereExp = null){
		$table = $this->table ?? null;
		$where = func_num_args() >= 1 ? $where : ($this->where ?? null);
		$whereExp = func_num_args() >= 2 ? $whereExp : ($this->whereExp ?? null);
		$base = $this->db->table($table);
		if($where || $whereExp){
			$this->tabledata = $this->applyScope($base, $where, $whereExp)->find($this->fields);
			
		}else{
			$this->tabledata = $base->find($this->fields);
		}

		$this->assertQuerySucceeded($this->tabledata);
		return true;
	}

	public function delete(...$deletors){

		foreach($deletors as $deletor){
			$table = $deletor['table'] ?? null;
			$where = $deletor['where'] ?? null;
			$whereExp = $deletor['whereExp'] ?? null;
			if(!$table){
				continue;
			}
			if (!$where && !$whereExp) {
				throw new \InvalidArgumentException('AutoForm delete requires an explicit record scope.');
			}
			$base = $this->db->table($table);
			if($where || $whereExp){
				if($where){
					if(isset($where[0]) && is_array($where[0])){
						foreach($where as $w){
							$base->where(... array_values($w));
						}
					}else{
						$base->where(... array_values($where));
					}
				}
				if($whereExp){
					if(isset($whereExp[0]) && is_array($whereExp[0])){
						foreach($whereExp as $w){
							$base->whereExp(... array_values($w));
						}
					}else{
						$base->whereExp(... array_values($whereExp));
					}
				}
				$delete = $base->delete();
				
			}else{
				$delete = $base->delete();
			}
			if(!$delete){
				$this->rollbackOnFailure();
				throw new \Exception(static::$msg['delete_failed'].'['.$this->db->error().']');
			}
		}
		return true;
	}

	public function update(...$updators){

		foreach($updators as $updator){
			$table = $updator['table'] ?? null;
			$where = $updator['where'] ?? null;
			$whereExp = $updator['whereExp'] ?? null;
			$data = $updator['data'] ?? null;
			if(!$table || !$data){
				continue;
			}
			if (!$where && !$whereExp) {
				throw new \InvalidArgumentException('AutoForm update requires an explicit record scope.');
			}

			$base = $this->db->table($table);
			if($where || $whereExp){
				if($where){
					if(isset($where[0]) && is_array($where[0])){
						foreach($where as $w){
							$base->where(... array_values($w));
						}
					}else{
						$base->where(... array_values($where));
					}
				}
				if($whereExp){
					if(isset($whereExp[0]) && is_array($whereExp[0])){
						foreach($whereExp as $w){
							$base->whereExp(... array_values($w));
						}
					}else{
						$base->whereExp(... array_values($whereExp));
					}
				}
				$update = $base->update($data);
				
			}else{
				$update = $base->update($data);
			}

			if(!$update){
				$this->rollbackOnFailure();
				throw new \Exception(static::$msg['update_failed'].'['.$this->db->error().']');
			}
		}
		return true;
	}

	public function add(...$creators){
		foreach($creators as $creator){
			$table = $creator['table'] ?? null;
			$data = $creator['data'] ?? null;
			if(!$table){
				continue;
			}
			$add = $this->db->table($table)->add($data, ($creator['index'] ?? $this->index) ?: null);
			if(!$add){
				$this->rollbackOnFailure();
			
				throw new \Exception(static::$msg['add_failed'].'['.$this->db->error().']');
			}
		}
		return true;
	}

	public function check(...$checkors){

		foreach($checkors as $key=>$checkor){
			if(!isset($checkor[0]) || !isset($checkor[1])){
				throw new \Exception((string)static::$msg['check_config_error'] ?? null);
			}
			$key = $checkor[0];
			$dataRule = $this->data[$key] ?? [];
			$name = $dataRule['name'] ?? $key;
			$check = $checkor[1];

			$options = $checkor[2] ?? null;
			switch($check){
				case 'exist':
					$transferkey = $checkor[2] ?? $key;
					$value = $checkor[3] ?? $this->_data[$key];
					if(is_array($value)){
						$this->tabledata = $this->db->table($this->table)->whereExp('wi',$transferkey,$value)->lock($this->trans && $this->db->supportsRowLocks())->select($this->fields);
					}else{
						$this->tabledata = $this->db->table($this->table)->where($transferkey,$value)->lock($this->trans && $this->db->supportsRowLocks())->find($this->fields);
					}
					$this->assertQuerySucceeded($this->tabledata);
					if($this->tabledata!==null && !is_array($this->tabledata)){
						$this->tabledata = $this->tabledata->toArray();
					}
					if(!$this->tabledata){
						$this->rollbackOnFailure();
						throw new \Exception('['.$name.']['.(is_array($value) ? implode(',',$value) : $value).']'.(static::$msg['is_not_exist'] ?? null));
					}
				break;
				case 'repeat':
					$value = $options ?? $this->_data[$key];
					$id = $this->id;
					$index = $this->index;
					if($this->hasIndexScope()){
						if(is_array($id)){
							$result = $this->db->table($this->table)->where($key,$value)->whereExp('wni',$index,$id)->lock($this->trans && $this->db->supportsRowLocks())->find($key);
						}else{
							$result = $this->db->table($this->table)->where($key,$value)->where($index,'<>',$id)->lock($this->trans && $this->db->supportsRowLocks())->find($key);
						}
					}else{
						$result = $this->db->table($this->table)->where($key,$value)->lock($this->trans && $this->db->supportsRowLocks())->find($key);
					}
					$this->assertQuerySucceeded($result);
					if($result){
						$this->rollbackOnFailure();
						throw new \Exception('['.$name.']['.$value.']'.(static::$msg['is_repeat'] ?? null));
					}
				break;
				default:
					try{
						$this->validator->check($this->_data[$key],$options);
					}catch(\Throwable $ex){
						throw new \Exception((string)$ex->getMessage());
					}
				break;
			}
			
		}
		return true;
	}
}
?>
