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

?>