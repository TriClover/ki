<?php
namespace mls\ki\Widgets;

/**
  (onAdd, onEdit, onDelete): Called on successful action.
    Callback will recieve PK of affected row.
	PK is passed as an array mapping field name to value.
	Return value if any is shown to the user.
  (beforeAdd, beforeEdit, beforeDelete): Called before action is tried.
    beforeAdd/beforeEdit recieve an associative array mapping FQ names to all values of the proposed row,
	beforeDelete recieves the PK.
	Function must return boolean TRUE to approve the action,
	any other value is taken as an error string to show the user.
  (onclickAdd, onclickEdit, onclickDelete): Contents of the javascript onclick event for these buttons.
*/
class DataTableEventCallbacks
{
	public $onAdd        = NULL;
	public $onEdit       = NULL;
	public $onDelete     = NULL;
	public $beforeAdd    = NULL;
	public $beforeEdit   = NULL;
	public $beforeDelete = NULL;
	public $onclickAdd   = NULL;
	public $onclickEdit  = NULL;
	public $onclickDelete= NULL;
	function __construct(
		callable $onAdd        = NULL,
		callable $onEdit       = NULL,
		callable $onDelete     = NULL,
		callable $beforeAdd    = NULL,
		callable $beforeEdit   = NULL,
		callable $beforeDelete = NULL,
		string   $onclickAdd   = NULL,
		string   $onclickEdit  = NULL,
		string   $onclickDelete= NULL)
	{
		$this->onAdd        = $onAdd;
		$this->onEdit       = $onEdit;
		$this->onDelete     = $onDelete;
		$this->beforeAdd    = $beforeAdd;
		$this->beforeEdit   = $beforeEdit;
		$this->beforeDelete = $beforeDelete;
		$this->onclickAdd   = ($onclickAdd    === NULL) ? '' : $onclickAdd;
		$this->onclickEdit  = ($onclickEdit   === NULL) ? '' : $onclickEdit;
		$this->onclickDelete= ($onclickDelete === NULL) ? '' : $onclickDelete;
	}
}
?>