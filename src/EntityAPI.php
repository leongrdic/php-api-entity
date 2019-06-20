<?php namespace Le;
// php-api-entity by Leon, MIT License

abstract class EntityAPI {
	const ACCESS_DENY = 0;

	const ACCESS_PUBLIC = 100;
	const ACCESS_PROTECTED = 200;
	const ACCESS_PRIVATE = 300;

	const LIST_PER_PAGE = 10;

	public static function fetch($params, $entities){
		$list = [];
		foreach($entities as $entity){
			$msg = $entity[0] . ' not found'; $ref = $entity[1] .'_'. $entity[2];

			$id = $params[$entity[1]][$entity[2]] ?? '';
			if(empty($id)) throw new APIException(API::HTTP_NOT_FOUND, $msg, $ref);

			try{ $object = $entity[0]::load($id); }
			catch(EntityNotFoundException $e){ throw new APIException(API::HTTP_NOT_FOUND, $msg, $ref); }

			array_push($list, $object);
		}

		return $list;
	}

	public static function entity_access($id){
		return EntityAPI::ACCESS_PUBLIC;
	}

	public static function get_get($params){
		API::validate($params, [ 'path' => [ [] ] ]); // check if there's exactly one path element

		$meta = static::$entity_options;
		$id = $params['path'][0];

		// handle multiple entities
		if(strpos($id, ',') !== false){
			$responses = [];
			$ids = explode(',', $id);
			foreach($ids as $val) if(!empty($val)){
				try {
					$response = static::get_get(array_merge($params, ['path' => [$val]]))->array();
				} catch(APIException $e){
					$response = $e->getResponse()->array();
				}
				array_push($responses, $response);
			}

			return new APIResponse(API::HTTP_MULTI, $responses);
		}

		// separating id from hash
		if(strpos($id, ':') !== false){
			$hash = explode(':', $id);
			$id = $hash[0];
			$hash = $hash[1] ?? null;
		}

		// checking access level
		$access = static::entity_access($id);
		if($access <= EntityAPI::ACCESS_DENY) throw new APIException(API::HTTP_FORBIDDEN, 'access denied to this object', null, $id);

		if(!isset($meta['cache'])) $meta['cache'] = -1;

		try{
			if(isset($hash) && $meta['class']::hash($id, $hash))
				return new APIResponse(API::HTTP_NOT_MODIFIED, null, $meta['cache'], $id);

			$res = $meta['class']::load($id);
		} catch(EntityNotFoundException $e){
			throw new APIException(API::HTTP_NOT_FOUND, 'entity not found', null, $id);
		}

		$data = $res->get();
		if(isset($meta['props']) && !empty($meta['props'])){
			foreach($data as $column => $value){
				$column_meta = $meta['props'][$column] ?? [];
				$column_access = $column_meta['read'] ?? EntityAPI::ACCESS_PUBLIC;
				if($access < $column_access) unset($data[$column]);
			}
		}

		return new APIResponse(API::HTTP_OK, $data, $meta['cache'], $id);
	}

	public static function post_set($params){
		API::validate($params, [ 'path' => [ [] ] ]); // check if there's exactly one path element

		$meta = static::$entity_options;
		$data = $params['data'];
		$id = $params['path'][0];

		if(empty($data)) throw new APIException(API::HTTP_BAD_REQUEST, 'missing data', 'data');

		// checking access level
		$access = static::entity_access($id);
		if($access <= EntityAPI::ACCESS_DENY) throw new APIException(API::HTTP_FORBIDDEN, 'access denied to this entity');

		try{
			$res = $meta['class']::load($id);
		} catch(EntityNotFoundException $e){
			throw new APIException(API::HTTP_NOT_FOUND, 'entity not found');
		}

		if(!isset($meta['props'])) $meta['props'] = [];
		foreach($data as $column => $value){
			$column_meta = $meta['props'][$column] ?? [];
			$column_access = $column_meta['write'] ?? EntityAPI::ACCESS_PRIVATE;
			if($access < $column_access) throw new APIException(API::HTTP_FORBIDDEN, 'access denied for writing the field \'' . $column . '\'');

			API::filter($value, $meta['props'][$column], "prop '" . $column . "'");
		}

		$res->set($data);

		return new APIResponse(API::HTTP_NO_CONTENT);
	}

	public static function list($conditions, $additional = [], $page = 0, $per_page = false){
		$meta = static::$entity_options;
		$per_page = $per_page !== false ? $per_page : ($meta['list_per_page'] ?? EntityAPI::LIST_PER_PAGE);
		$offset = $per_page * $page;

		$result = [];
		$result['total'] = $meta['class']::count($conditions);
		$result['per_page'] = $per_page;
		$result['page_count'] = ceil($result['total']/$per_page);
		$result['data'] = $meta['class']::find(
			$conditions,
			array_merge($additional, ['limit' => $per_page, 'offset' => $offset])
		)->array();
		return $result;
	}

	public static function disabled(){
		throw new APIException(API::HTTP_NOT_IMPLEMENTED, 'unknown action');
	}
}
