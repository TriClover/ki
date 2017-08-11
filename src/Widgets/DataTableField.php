<?php
namespace mls\ki\Widgets;

class DataTableField
{
	//Identification
	public $name;            //Always required. If NULL, use these settings for fields not specified.
	public $table = NULL;    //NULL = same as main table. Other tables will be LEFT JOINed in on the first foreign key found. For tables with no direct foreign key it will look for a many-to-many-relation table named maintable_othertable with appropriate foreign keys.
	public $alias = NULL;    //NULL = same as $name unless $table is specified in which case it will be $table.$name
	//Where to use the field
	public $show = true;
	public $edit = false;    //Ignored if $show = false
	public $add  = true;     //What to do for this field when adding new rows. true=allow editing, false=disallow and use default/auto value, and string/number/NULL=disallow and use this value instead. Ignored if adding new rows is not allowed or $show = false.
	//Validation
	public $constraints = array(); //HTML5 form validation constraints. These will be used directly in the form and interpreted for server-side checks.
	//Presentation
	public $outputFilter = NULL; //Function that recieves table cell contents and outputs what they will be replaced with. Second parameter is the cell type: (show, edit, add)
	
	function __construct($name,
	                     $table = NULL,
						 $alias = NULL,
						 $show = true,
						 $edit = false,
						 $add = NULL,
						 $constraints = array(),
						 $outputFilter = NULL)
	{
		$this->name = $name;
		$this->table = $table;
		if($alias === NULL)
			$this->determineAlias();
		else
			$this->alias = $alias;
		$this->show = $show;
		$this->edit = $edit;
		$this->add = $add;
		$this->constraints = ($constraints === NULL) ? array() : $constraints;
		$this->outputFilter = $outputFilter;
	}
	
	function determineAlias()
	{
		$this->alias = ($this->table === NULL) ? $this->name : ($this->table.'.'.$this->name);
	}
	
	//schema
	public $dataType     = NULL;
	public $nullable     = NULL;
	public $keyType      = NULL; //PRI, UNI, MUL
	public $defaultValue = NULL;
	public $extra        = NULL;
}
?>