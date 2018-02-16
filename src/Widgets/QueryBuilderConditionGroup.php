<?php
namespace mls\ki\Widgets;

/**
* Stores conditions from a QueryBuilder connected by an AND or OR
*/
class QueryBuilderConditionGroup
{
	public $boolOp;
	public $conditions = [];
	
	/**
	* @param boolOp must be one of [AND, OR, XOR]
	* @param conditions array of QueryBuilderCondition objects
	*/
	function __construct(string $boolOp, array $conditions)
	{
		$this->boolOp     = $boolOp;
		$this->conditions = $conditions;
	}
	
	/**
	* @return a SQL snippet expressing this condition group
	*/
	function getSQL()
	{
		$snips = [];
		foreach($this->conditions as $cond)
		{
			if($cond !== null)
			{
				$sql = $cond->getSQL();
				if(!empty($sql)) $snips[] = $sql;
			}
		}

		if(!empty($snips))
		{
			return '(' . implode(' '.$this->boolOp.' ', $snips) . ')';
		}
		return '';
	}
}

?>