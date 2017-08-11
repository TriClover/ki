<?php
namespace mls\ki\Widgets;
use \mls\ki\DataTable;

/**
  (onAdd, onEdit, onDelete): Called on successful action.
    Callback will recieve a list of affected rows by PK.
	PK is passed as an array mapping field name to value.
	Return value if any is shown to the user.
  (beforeAdd, beforeEdit, beforeDelete): Called before action is tried.
    beforeAdd/beforeEdit recieve an array with all values of the proposed row,
	beforeDelete recieves the PK.
	Function must return boolean TRUE to approve the action,
	any other value is taken as an error string to show the user.
*/
class DataTableEventCallbacks
{
	public $onAdd        = NULL;
	public $onEdit       = NULL;
	public $onDelete     = NULL;
	public $beforeAdd    = NULL;
	public $beforeEdit   = NULL;
	public $beforeDelete = NULL;
	function __construct(
		$add          = NULL,
		$edit         = NULL,
		$delete       = NULL,
		$beforeadd    = NULL,
		$beforeedit   = NULL,
		$beforedelete = NULL)
	{
		$this->onAdd        = $add;
		$this->onEdit       = $edit;
		$this->onDelete     = $delete;
		$this->beforeAdd    = $beforeadd;
		$this->beforeEdit   = $beforeedit;
		$this->beforeDelete = $beforedelete;
	}
}
?>