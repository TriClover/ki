<?php
namespace mls\ki\Widgets;

class DataTableField
{
	//Identification
	public $name;            //Always required. If NULL, use these settings for fields not specified.
	public $table;
	public $alias = NULL;    //NULL = same as $name unless $table is specified in which case it will be $table.$name
	//Where to use the field
	public $show = true;
	public $edit = false;    //Ignored if $show = false
	public $add  = true;     //What to do for this field when adding new rows. true=allow editing, false=disallow and use default/auto value, and string/number/NULL=disallow and use this value instead. Ignored if adding new rows is not allowed or $show = false.
	//Validation
	public $constraints = array(); //HTML5 form validation constraints. These will be used directly in the form and interpreted for server-side checks.
	//Presentation
	public $outputFilter = NULL; //Function that recieves table cell contents and outputs what they will be replaced with. Second parameter is the cell type: (show, edit, add)
	
	function __construct(         $name,
	                     string   $table,
						          $alias = NULL,
						 bool     $show = true,
						 bool     $edit = false,
						          $add = NULL,
						 array    $constraints = array(),
						 callable $outputFilter = NULL)
	{
		$this->name = $name;
		$this->table = $table;
		if($alias === NULL)
			$this->alias = $this->fqName(false);
		else
			$this->alias = $alias;
		$this->show = $show;
		$this->edit = $edit;
		$this->add = $add;
		$this->constraints = ($constraints === NULL) ? array() : $constraints;
		$this->outputFilter = $outputFilter;
	}
	
	function fqName(bool $quoted = false)
	{
		$q = $quoted ? '`' : '';
		return $q . $this->table . $q . '.' . $q . $this->name . $q;
	}
	
	//schema
	public $dataType     = NULL;
	public $nullable     = NULL;
	public $keyType      = NULL; //PRI, UNI, MUL
	public $defaultValue = NULL;
	public $extra        = NULL;
	
	//metadata
	public $serialNum = NULL; //index with which this field was originally provided to the DataTable, for compact referencing
}
?>