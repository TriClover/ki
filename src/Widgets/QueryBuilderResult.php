<?php
namespace mls\ki\Widgets;

/**
* Stores all data from a QueryBuilder
*/
class QueryBuilderResult
{
	public $fieldsToShow = [];
	public $rootConditionGroup = NULL;
	public $sortOrder = [];
	
	/**
	* @param fieldsToShow array of aliases for the fields to show, in the order to show them
	* @param rootConditionGroup The root QueryBuilderConditionGroup for the user's conditions
	* @param sortOrder array of aliases for the fields to sort on, in descending order of importance. Empty array means to sorting, return results in natural/fastest order
	*/
	function __construct(array $fieldsToShow = [], QueryBuilderConditionGroup $rootConditionGroup = null, array $sortOrder = [])
	{
		$this->fieldsToShow       = $fieldsToShow;
		$this->rootConditionGroup = $rootConditionGroup;
		$this->sortOrder          = $sortOrder;
	}
	
	/**
	* @return a SQL snippet expressing the rootConditionGroup
	*/
	function getFilterSQL()
	{
		if($this->rootConditionGroup === NULL) return '';
		return $this->rootConditionGroup->getSQL();
	}
}

/*
Binary format

number of shown fields, 12 bits
IDs of shown fields, 12 bits each
000000000000, if number of fields was even

number of sorted fields, 12 bits
0000
specified number of sorted fields:
	colID, 12 bits
	direction, 1 bit, 0=asc,1=desc
	000

root condition group spec:
	0 (group):
		boolOpId, 2 bits
		number of conditions on this level, 5 bits
		recurse
	1 (condition):
		value starting byte, 15 bits
		value length in bytes, 8 bits
		colID, 12 bits
		opID, 4 bits
flat storage of condition values follows
*/
?>