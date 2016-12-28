<?php

class EMaterializedPathBehavior extends CActiveRecordBehavior {

	public $record_id = 'id';
	public $record_path = 'path';
	public $record_level = 'level';
	public $depth = 3;

	protected $originalPath; //старый путь перед обновлением
	protected $new_path; //новый путь после обновления

	/**
	 * @param CModelEvent $event event parameter
	 */
	public function afterFind($event) {

		// save original parametrs
		$this->originalPath = $this->owner->{$this->record_path};
		return parent::afterFind($event);
	}

	/**
	 * @param CModelEvent $event event parameter
	 * перед сохранением проверям путь 
	 */
	public function beforeValidate($event) {
		$record_path = $this->record_path;
		// check at loop for new parent_id

		if ($this->owner->$record_path != $this->originalPath) {
			if (!$this->_checkPath($this->owner->$record_path)) {
				$this->owner->addError($record_path, 'Такой путь уже существует!!!');
			}
			if ((strlen($this->owner->$record_path)%$this->depth)==1) {
				$this->owner->addError($record_path, 'Такой путь не кратен глубине');
			}
			//echo $this->_getParentByPath($this->owner->$record_path);
			//path before save and after save _getParentByPath($old_path, $new_path) - заглушка от рекурсии
			if ($this->_getParentByPath($this->owner->$record_path)==$this->originalPath && $this->originalPath!=''){
				$this->owner->addError($record_path, 'Нельзя вложить родителя в собственную ветку. Измените путь.');
			}
		}		
		return parent::beforeValidate($event);
	}

// после сохранения обновляем пути
	public function afterSave($event) {

		$record_path = $this->record_path;
		// if change pk|path
		if ($this->owner->$record_path != $this->originalPath && $this->originalPath!='') {
			// throw new CException('Тут обновление записей');
			// тут есть ошибка!  при перемещении ветки срабатывает event и ветка обновляется дважды
			// $this->_changeBranchPath($this->originalPath, $this->owner->$record_path);
		}
		return parent::afterSave($event);
	}

// получаем ветку по id

	public function getNodeByID($node_id) {
		$owner = $this->getOwner();
		$owner->getDbCriteria()->addCondition($owner->getTableAlias() . '.' . $this->record_id . '=' . (int) $node_id);

		return $owner->find();
	}
	
	public function getNodeLevel($path) {
		return strlen($path) / $this->depth;
	}

    public function getRootPath($path) {
		return mb_substr($path, 0, $this->depth);
	}

	public function getFullTree() {
		$owner = $this->getOwner();
		$criteria = new CDbCriteria();
		$alias = $owner->getTableAlias();

		$criteria->mergeWith(array(
			 'order' => $alias . '.' . $this->record_path,
		));

		$owner->setDbCriteria($criteria);
		return $owner;
	}

	public function getBranch($node) {
		$owner = $this->getOwner();
		$criteria = new CDbCriteria();
		$alias = $owner->getTableAlias();

		$node_info = $this->getNodeByID($node);

		$criteria->mergeWith(array(
			 'condition' => $alias . '.' . $this->record_path . ' IN ("' . join('", "', $this->_getParentChuncks($node_info->{$this->record_path})) . '")' .
			 ' OR ' . $alias . '.' . $this->record_path . ' LIKE "' . $node_info->{$this->record_path} . '%"',
			 'order' => $alias . '.' . $this->record_path,
		));

		$owner->setDbCriteria($criteria);
		return $owner;
	}

	public function getParents($node, $depth = null) {
		$owner = $this->getOwner();
		$criteria = new CDbCriteria();
		$alias = $owner->getTableAlias();

		$node_info = $this->getNodeByID($node);
		if ($node_info !== null) {
			$criteria->mergeWith(array(
				 'condition' => $alias . '.' . $this->record_path . ' IN ("' . join('", "', $this->_getParentChuncks($node_info->{$this->record_path})) . '")' .
				 (isset($depth) ?
							' AND LENGTH(' . $alias . '.' . $this->record_path . ') >= ' .
							(strlen($node_info->{$this->record_path}) - (int) $depth * $this->depth) : ''
				 ),
				 'order' => $alias . '.' . $this->record_path,
			));

			$owner->setDbCriteria($criteria);
		}
		return $owner;
	}

	public function getChildren($node, $depth = null) {
		$owner = $this->getOwner();
		$criteria = new CDbCriteria();
		$alias = $owner->getTableAlias();

		$node_info = $this->getNodeByID($node);

		$criteria->mergeWith(array(
			 'condition' => $alias . '.' . $this->record_path . ' LIKE "' . $node_info->{$this->record_path} . '_%"' .
			 (isset($depth) ?
						'AND LENGTH(' . $alias . '.' . $this->record_path . ') <= ' .
						(strlen($node_info->{$this->record_path}) + (int) $depth * $this->depth) : ''
			 ),
			 'order' => $alias . '.' . $this->record_path,
		));

		$owner->setDbCriteria($criteria);
		return $owner;
	}

// получить детей по пути
	public function getChildrens($path, $depth = null) {
		$owner = $this->getOwner();
		$criteria = new CDbCriteria();
		$alias = $owner->getTableAlias();

		$criteria->mergeWith(array(
			 'condition' => $alias . '.' . $this->record_path . ' LIKE "' . $path . '%_"' .
			 (isset($depth) ?
						'AND LENGTH(' . $alias . '.' . $this->record_path . ') <= ' .
						(strlen($path) + (int) $depth * $this->depth) : ''
			 ),
			 'order' => $alias . '.' . $this->record_path,
		));

		$childrens = $this->owner->findAll($criteria);
		return $childrens;
	}

// 
	public function nodeMove($action='up'){
		$field = $this->owner->getTableAlias(). '.' . $this->record_path;
		$nodes=$this->_getMove($action);
		
	//dump($data_arr);
		foreach($nodes AS $node){
			$data_arr[]=array(
				 'path' => $node->{$this->record_path},
				 'id' => $node->{$this->record_id},
				 'childrens'=>$this->getChildrens($node->{$this->record_path})
			);
		}

	if(count($data_arr)==2){
		$connection	 = Yii::app()->db;
			$transaction = $connection->beginTransaction();
		try {
				$this->owner->updateByPk($data_arr[0]['id'], array($this->record_path => $data_arr[1]['path']));
				$this->owner->updateByPk($data_arr[1]['id'], array($this->record_path => $data_arr[0]['path']));
				$this->_updChildrens($data_arr[0]['childrens'], $data_arr[0]['path'], $data_arr[1]['path']);
				$this->_updChildrens($data_arr[1]['childrens'], $data_arr[1]['path'], $data_arr[0]['path']);
			$transaction->commit();
		} catch (CException $e) {
			$transaction->rollBack();
			throw $e;
		}
			Yii::app()->user->setFlash('success', 'Данные успешно перемещены '. $action);
		}	
		else{
			Yii::app()->user->setFlash('error', 'Достигнут предел сортировки');
		}
	}
	
	private function _getMove($action){
		$criteria = new CDbCriteria();
		$field = $this->owner->getTableAlias(). '.' . $this->record_path;
		
		switch ($action) {
			case 'up':
				$criteria->mergeWith(array(
					 'condition' => $field .' <= "' . $this->originalPath . '" AND LENGTH(' . $field . ') = ' .(strlen($this->originalPath)),
					 'order' => $field . ' DESC',
					 'limit' => 2
				));
				break;
			case 'down':
				$criteria->mergeWith(array(
					 'condition' => $field .' >= "' . $this->originalPath . '" AND LENGTH(' . $field . ') = ' .(strlen($this->originalPath)),
					 'order' => $field . ' ASC',
					 'limit' => 2
				));
				break;

			default:
				break;
		}


		$childrens = $this->owner->findAll($criteria);
		return $childrens;
		
	}
	
	
/* получить для селекта по ID узла
echo $form->dropDownList($model, 'parent_id',
		  $model->getForSelect($model->id), 
		  array('empty' => 'Select a category') 
);
*/
	public function getForSelect($node, $depth = null) {
		$owner = $this->getOwner();
		$criteria = new CDbCriteria();
		$alias = $owner->getTableAlias();
		$node_info = $this->getNodeByID($node);
		$criteria->mergeWith(array(
			 'condition' => $alias . '.' . $this->record_path . ' NOT LIKE "' . $node_info->{$this->record_path} . '%_"' .
			 (isset($depth) ?
						'AND LENGTH(' . $alias . '.' . $this->record_path . ') <= ' .
						(strlen($node) + (int) $depth * $this->depth) : ''
			 ),
			 'order' => $alias . '.' . $this->record_path,
		));
		//$criteria->addInCondition('t.id', $ids);
		$childrens = $this->owner->findAll($criteria);

		$data = array();
		foreach ($childrens AS $c) {
			$data[$c->id] = str_repeat(" - ", ($c->level)) . $c->menu_name;
		}

		return $data;
	}

	public function getByLevel($level = 1) {
		$owner = $this->getOwner();
		$criteria = $owner->getDbCriteria();
		$alias = $owner->getTableAlias();

		$criteria->mergeWith(array(
			 'condition' => 'LENGTH(' . $alias . '.' . $this->record_path . ') = ' . ($level * $this->depth),
			 'order' => $alias . '.' . $this->record_path,
		));


		$owner->setDbCriteria($criteria);

		return $owner;
	}

// получение максимального пути
	public function getMax($path = false) {
		if (!$path) {
			$depth = $this->depth;
			$where = '';
		} else {
			$where = " AND `path` LIKE '{$path}%'";
			$depth = $this->depth + strlen($path);
		}

		$query = 'SELECT MAX(`path`) AS max FROM `' . $this->owner->tableName() . "` WHERE LENGTH(`path`) = {$depth}" . $where;

		$connect = Yii::app()->db;
		$command = $connect->createCommand($query);
		$max = $command->queryRow();

		if ($max['max'] === null) {
			$tmp = 1;
		} else {
			$tmp_str = str_split($max['max'], $this->depth);
			$tmp = (int) array_pop($tmp_str) + 1;
		}
		return sprintf($path . "%1$0{$this->depth}d", $tmp);
	}

//получаем ветку по ID родетелей
	public function getByIds($ids = array()) {
		$owner = $this->getOwner();
		$criteria = $owner->getDbCriteria();
		$alias = $owner->getTableAlias();

		if (empty($ids)) {
			$criteria->mergeWith(array(
				 'condition' => $alias . '.id IN(0)',
			));
			$owner->setDbCriteria($criteria);
			return $owner;
		}

		$criteria->mergeWith(array(
			 'condition' => $alias . '.id IN(' . join(',', $ids) . ')',
			 'order' => $alias . '.menu_name',
		));

		$owner->setDbCriteria($criteria);
		return $owner;
	}
//------------------------------------------------------------------------------

// получаем часть ветки
	private function _getParentChuncks($path) {
		$chunks = str_split($path, $this->depth);
		$where = '';
		foreach ($chunks as $a => $b) {
			$where.=$b;
			$chunks[$a] = $where;
		}

		array_pop($chunks);
		return $chunks;
	}
	
// перемещение ветки
	protected function _changeBranchPath($old_path, $new_path) {
		$childrens = $this->getChildrens($old_path);
		$this->_updChildrens($childrens, $old_path, $new_path);
	}
	
	protected function _updChildrens($childrens, $old_path, $new_path){
		if($childrens){
			foreach ($childrens as $c) {
				$c->{$this->record_path} = $new_path . mb_substr($c->{$this->record_path}, mb_strlen($old_path), mb_strlen($c->{$this->record_path}) - mb_strlen($old_path));
				$c->{$this->record_level} = $this->getNodeLevel($c->{$this->record_path});
				$c->update();
			}
		}
	}

// проверка пути
	protected function _checkPath($path) {
		$count = $this->owner->count($this->record_path . ' = :path', array(':path' => $path));
		if ($count > 0)
			return false;

		return true;
	}

// проверка родителя
	protected function _getParentByPath($path){
			return substr($path, 0, -$this->depth);
	}
	
	protected function isRoot($path){
		if(strlen($path)==$this->depth){
			return true;
		}
		return false;
	}
	
	
	
}
