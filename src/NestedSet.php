<?php

namespace Kotlin360\Nestedset;


use Phalcon\Db\AdapterInterface;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Behavior;
use Phalcon\Mvc\Model\BehaviorInterface;
use Phalcon\Mvc\Model\Exception;
use Phalcon\Mvc\Model\ResultsetInterface;
use Phalcon\Mvc\ModelInterface;

class NestedSet extends Behavior implements BehaviorInterface
{
	use EventManagerAwareTrait;

	const EVT_TYPE_QUERY = 'nestedset';

	const EVT_DESCENDANTS = 'Descendants';
	const EVT_ANCESTORS = 'Ancestors';
	const EVT_PARENT = 'Parent';
	const EVT_PREV = 'Prev';
	const EVT_NEXT = 'Next';
	const EVT_ROOTS = 'Roots';

	/**
	 * @var AdapterInterface|null
	 */
	private $db;

	/**
	 * @var ModelInterface|null
	 */
	private $owner;

	private $hasManyRoots = true;
	private $rootAttribute = 'root';
	private $leftAttribute = '_lft';
	private $rightAttribute = '_rgt';
	private $levelAttribute = 'level';
	private $primaryKey = 'id';
	private $ignoreEvent = false;
	private $deleted = false;

	public function __construct($options = null)
	{
		if (isset($options['db']) && $options['db'] instanceof AdapterInterface) {
			$this->db = $options['db'];
		}

		if (isset($options['hasManyRoots'])) {
			$this->hasManyRoots = (bool)$options['hasManyRoots'];
		}

		if (isset($options['rootAttribute'])) {
			$this->rootAttribute = $options['rootAttribute'];
		}

		if (isset($options['leftAttribute'])) {
			$this->leftAttribute = $options['leftAttribute'];
		}

		if (isset($options['rightAttribute'])) {
			$this->rightAttribute = $options['rightAttribute'];
		}

		if (isset($options['levelAttribute'])) {
			$this->levelAttribute = $options['levelAttribute'];
		}

		if (isset($options['primaryKey'])) {
			$this->primaryKey = $options['primaryKey'];
		}
	}


	/**
	 * Calls a method when it's missing in the model
	 * @param ModelInterface $model
	 * @param string         $method
	 * @param null           $arguments
	 * @return mixed|null|string
	 * @throws Exception
	 */
	public function missingMethod(ModelInterface $model, $method, $arguments = null)
	{
		if (!method_exists($this, $method)) {
			return null;
		}

		$this->getDbHandler($model);
		$this->setOwner($model);

		return call_user_func_array([$this, $method], $arguments);
	}

	/**
	 * @return ModelInterface
	 */
	public function getOwner()
	{
		if (!$this->owner instanceof ModelInterface) {
			trigger_error("Owner isn't a valid ModelInterface instance.", E_USER_WARNING);
		}

		return $this->owner;
	}

	public function setOwner(ModelInterface $owner)
	{
		$this->owner = $owner;
	}

	public function getIsNewRecord()
	{
		return $this->getOwner()->getDirtyState() == Model::DIRTY_STATE_TRANSIENT;
	}

	/**
	 * Returns if the current node is deleted.
	 * @return boolean whether the node is deleted.
	 */
	public function getIsDeletedRecord()
	{
		return $this->deleted;
	}


	/**
	 * Determines if node is leaf.
	 * @return boolean whether the node is leaf.
	 */
	public function isLeaf()
	{
		$owner = $this->getOwner();

		return $owner->{$this->rightAttribute} - $owner->{$this->leftAttribute} === 1;
	}

	/**
	 * Determines if node is root.
	 * @return boolean whether the node is root.
	 */
	public function isRoot()
	{
		return $this->getOwner()->{$this->leftAttribute} == 1;
	}

	/**
	 * Determines if node is descendant of subject node.
	 * @param  \Phalcon\Mvc\ModelInterface $subj the subject node.
	 * @return boolean                     whether the node is descendant of subject node.
	 */
	public function isDescendantOf($subj)
	{
		$owner  = $this->getOwner();
		$result = ($owner->{$this->leftAttribute} > $subj->{$this->leftAttribute})
			&& ($owner->{$this->rightAttribute} < $subj->{$this->rightAttribute});

		if ($this->hasManyRoots) {
			$result = $result && ($owner->{$this->rootAttribute} === $subj->{$this->rootAttribute});
		}

		return $result;
	}

	/**
	 * Named scope. Gets descendants for node.
	 * @param int     $depth   the depth.
	 * @param boolean $addSelf If TRUE - parent node will be added to result set.
	 * @return ResultsetInterface
	 */
	public function descendants($depth = null, $addSelf = false)
	{
		$owner = $this->getOwner();

		$query = $owner::query()
			->where($this->leftAttribute . '>' . ($addSelf ? '=' : null) . $owner->{$this->leftAttribute})
			->andWhere($this->rightAttribute . '<' . ($addSelf ? '=' : null) . $owner->{$this->rightAttribute})
			->orderBy($this->leftAttribute);

		if ($depth !== null) {
			$query = $query->andWhere($this->levelAttribute . '<=' . ($owner->{$this->levelAttribute} + $depth));
		}

		if ($this->hasManyRoots) {
			$query = $query->andWhere($this->rootAttribute . '=' . $owner->{$this->rootAttribute});
		}

		$this->fire(
			self::EVT_TYPE_QUERY . ':before' . self::EVT_DESCENDANTS,
			$query,
			[
				'owner'   => $owner,
				'depth'   => $depth,
				'addSelf' => $addSelf,
			]
		);

		return $query->execute();
	}

	/**
	 * Named scope. Gets children for node (direct descendants only).
	 * @return ResultsetInterface
	 */
	public function children()
	{
		return $this->descendants(1);
	}

	/**
	 * Named scope. Gets ancestors for node.
	 * @param  int $depth the depth.
	 * @return ResultsetInterface
	 */
	public function ancestors($depth = null)
	{
		$owner = $this->getOwner();

		$query = $owner::query()
			->where($this->leftAttribute . '<' . $owner->{$this->leftAttribute})
			->andWhere($this->rightAttribute . '>' . $owner->{$this->rightAttribute})
			->orderBy($this->leftAttribute);

		if ($depth !== null) {
			$query = $query->andWhere($this->levelAttribute . '>=' . ($owner->{$this->levelAttribute} - $depth));
		}

		if ($this->hasManyRoots) {
			$query = $query->andWhere($this->rootAttribute . '=' . $owner->{$this->rootAttribute});
		}

		$this->fire(
			self::EVT_TYPE_QUERY . ':before' . self::EVT_ANCESTORS,
			$query,
			[
				'owner' => $owner,
				'depth' => $depth,
			]
		);

		return $query->execute();
	}

	/**
	 * Named scope. Gets root node(s).
	 * @return ResultsetInterface
	 */
	public function roots()
	{
		$owner = $this->getOwner();

		$query = $owner::query()
			->andWhere($this->leftAttribute . ' = 1');

		$this->fire(
			self::EVT_TYPE_QUERY . ':before' . self::EVT_ROOTS,
			$query,
			[
				'owner' => $owner,
			]
		);

		return $owner::find($query->getParams());
	}

	/**
	 * Named scope. Gets parent of node.
	 * @return \Phalcon\Mvc\ModelInterface
	 */
	public function parent()
	{
		$owner = $this->getOwner();

		$query = $owner::query()
			->where($this->leftAttribute . '<' . $owner->{$this->leftAttribute})
			->andWhere($this->rightAttribute . '>' . $owner->{$this->rightAttribute})
			->orderBy($this->rightAttribute)
			->limit(1);

		if ($this->hasManyRoots) {
			$query = $query->andWhere($this->rootAttribute . '=' . $owner->{$this->rootAttribute});
		}

		$this->fire(
			self::EVT_TYPE_QUERY . ':before' . self::EVT_PARENT,
			$query,
			[
				'owner' => $owner,
			]
		);

		return $query->execute()->getFirst();
	}

	/**
	 * Named scope. Gets previous sibling of node.
	 * @return ModelInterface
	 */
	public function prev()
	{
		$owner = $this->getOwner();
		$query = $owner::query()
			->where($this->rightAttribute . '=' . ($owner->{$this->leftAttribute} - 1));

		if ($this->hasManyRoots) {
			$query = $query->andWhere($this->rootAttribute . '=' . $owner->{$this->rootAttribute});
		}

		$this->fire(
			self::EVT_TYPE_QUERY . ':before' . self::EVT_PREV,
			$query,
			[
				'owner' => $owner,
			]
		);

		return $query->execute()->getFirst();
	}

	/**
	 * Named scope. Gets next sibling of node.
	 * @return ModelInterface
	 */
	public function next()
	{
		$owner = $this->getOwner();
		$query = $owner::query()
			->where($this->leftAttribute . '=' . ($owner->{$this->rightAttribute} + 1));

		if ($this->hasManyRoots) {
			$query = $query->andWhere($this->rootAttribute . '=' . $owner->{$this->rootAttribute});
		}

		$this->fire(
			self::EVT_TYPE_QUERY . ':before' . self::EVT_NEXT,
			$query,
			[
				'owner' => $owner,
			]
		);

		return $query->execute()->getFirst();
	}

	/**
	 * Gets DB handler.
	 * @param ModelInterface $model
	 * @return AdapterInterface
	 * @throws Exception
	 */
	private function getDbHandler(ModelInterface $model)
	{
		if (!$this->db instanceof AdapterInterface) {
			if ($model->getDi()->has('db')) {
				$db = $model->getDi()->getShared('db');
				if (!$db instanceof AdapterInterface) {
					throw new Exception('The "db" service which was obtained from DI is invalid adapter.');
				}
				$this->db = $db;
			} else {
				throw new Exception('Undefined database handler.');
			}
		}

		return $this->db;
	}
}