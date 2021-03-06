<?php
namespace mls\ki\Widgets;
use \mls\ki\Database;
use \mls\ki\Exporter;
use \mls\ki\Log;
use \mls\ki\Util;

/**
* DataTable - generic data manipulation and reporting tool.
* Editing only works when there is a primary key on the specified table(s)
*/
class DataTable extends Form
{
	//setup parameters
	public    $title;            //title for this widget - needed to separate multiple DataTables on the same page
	public    $table;            //name of table to use. If array, will use first as base and the rest will be LEFT JOINed in on the first matching foreign key found (FK in base table connected to PK in other table). If a given table to join is referenced by more than one field it will get joined once for each field, with alias in the form `referencedTable_referencingFieldInMainTable`. Tables other than the first, instead of being a string and joining with key autodetection, can instead be a DataTableJoin object where you manually specify the fields to join on -- just make sure that if you join the same table more than once you supply a unique alias for each one in the DataTableJoin constructor.
	public    $fields;           //array of DataTableField objects specifying what to do with each field. Fields not specified will get the defaults shown in the DataTableField class. A field with NULL for the name overrides what is used for fields not specified.
	protected $allow_add;        //true: allow adding rows. false: dont.
	protected $allow_delete;     //true: allow deleting rows. false: don't. string: allow but set this field=false (table.field) instead of actually deleting
	protected $filter;           //if string, use as sql fragment in where clause to filter output: useful if table has "enabled" field.
	protected $rows_per_page;
	protected $show_exports;     //true: show all export buttons. false: don't. array: show only specified formats (xlsx,csv,xml,json,sql)
	protected $show_querybuilder;//allow filtering, sorting, etc
	protected $show_querylist;   //allow loading queries. if $show_querybuilder is false you only see the results and not the conditions
	protected $show_querysave;   //allow saving queries. Only works if $show_querybuilder and $show_querylist are true.
	protected $eventCallbacks;   //DataTableEventCallbacks object
	protected $buttonCallbacks;  //array of CallbackButton objects that get their own buttons in the table for each row. The functions recieve the PK.
	protected $headerText;       //text always shown at top of table
	protected $db;               //database connection. Defaults to the framework default DB.
	
	//setup, calculated
	protected $inPrefix;         //prefix of all HTML input names
	protected $customCallbacks = array();
	protected $allow_edit;
	protected $allow_show;
	public    $joinTables = array();
	protected $joinString;
	public    $alias2fq = array(); //keys = aliases of all fields, values = unquoted FQ field names
	protected $queryBuilder = NULL;
	public    $defaultField = NULL; //DataTableField with default values to clone as the basis for fields not specified
	
	//schema
	public    $pk = array();   //fields that are in the primary key
	public    $autoCol = NULL; //field with auto_increment, NULL if none
	
	//state from params
	protected $page = 1;
	
	//state, internal
	protected $setupOK       = true;    //Whether the setup was successful. If false, no other features will try to do anything.
	protected $outputMessage = array();
	
	//const
	protected $textTypes = array('email', 'password', 'search', 'tel', 'text', 'url'); //html form types where attributes like "size" and "maxlength" are valid
	
	/**
	* Set up the DataTable, doing all necessary validation on the configuration and
	* translating it to a form easier for the internal code to use
	*/
	function __construct(string $title,
	                            $table,
	                     array  $fields            = array(),
	                     bool   $allow_add         = false,
	                     bool   $allow_delete      = false,
	                     string $filter            = '',
	                     int    $rows_per_page     = 50,
	                     bool   $show_exports      = false,
	                     bool   $show_querybuilder = false,
	                     bool   $show_querylist    = false,
	                     bool   $show_querysave    = false, //todo
	    DataTableEventCallbacks $eventCallbacks    = NULL,
						 array  $buttonCallbacks   = NULL,
	                     string $headerText        = '',
	                   Database $db                = NULL)
	{
		//save parameters
		$this->title             = preg_replace('/[^A-Za-z0-9_]/','',$title);
		$this->allow_add         = $allow_add;
		$this->allow_delete      = $allow_delete;
		$this->filter            = mb_strlen($filter) > 0 ? $filter : '1';
		$this->rows_per_page     = $rows_per_page;
		$this->show_exports      = $show_exports;
		$this->show_querybuilder = $show_querybuilder;
		$this->show_querylist    = $show_querylist;
		$this->show_querysave    = $show_querysave;
		$this->eventCallbacks    = ($eventCallbacks !== NULL) ? $eventCallbacks : new DataTableEventCallbacks(NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
		$this->buttonCallbacks   = $buttonCallbacks;
		$this->headerText        = $headerText;
		$this->db                = ($db === NULL) ? Database::db() : $db;
		
		$db = $this->db;
		
		//calculate more setup info
		$this->inPrefix = 'ki_datatable_' . htmlspecialchars($this->title) . '_';
		
		//identify keys for multi-table
		if(is_array($table))
		{
			$this->table = $table[0];
			$joinTables = [];
			foreach($table as $key => $value)
			{
				if($key != 0) $joinTables[$key-1] = $value;
			}
			$this->joinTables = DataTableJoin::createAll($this->table, $joinTables, $db);
		}else{
			$this->table = $table;
			$table = [$table];
		}
		
		//process field definitions
		$this->defaultField = new DataTableField(NULL, $this->table);
		if($fields === NULL) $fields = array();
		if(!is_array($fields))
		{
			Log::error('DataTable ' . $this->title . ' "fields" was not array or NULL.');
			$this->setupOK = false;
			return;
		}
		$this->fields = array();
		foreach($fields as $inputField)
		{
			if($inputField->name === NULL)
			{
				//field config for "NULL" will be used for any fields where a config was not provided
				$this->defaultField = $inputField;
			}else{
				//store the given field config in the DataTable indexed by field name
				$this->fields[$inputField->fqName()] = $inputField;
			}
		}
		
		//get schema info
		$schemaResult = DataTableField::fillSchemaInfoAll($this);
		if($schemaResult === false)
		{
			$this->setupOK = false;
			return;
		}

		//joins, for multi-table
		$joins = [];
		if(!empty($this->joinTables))
		{
			foreach($this->joinTables as $join) $joins[] = $join->joinSql();
		}
		$this->joinString = implode(' ', $joins);
		
		//check schema and setup
		
		//check validity of fields to show, add auto generated constraints
		$this->allow_show = false;
		foreach($this->fields as $fname => $field)
		{
			//Pure expression fields
			if($field->table == '') continue;
			
			if($field->dataType === NULL || ($field->dataType == 'virtual' && $field->manyToMany === false))
			{
				unset($this->fields[$fname]);
				Log::warn('DataTable ' . $this->title . ' asked to show a field that is not in any included table and is not a valid virtual field: ' . $fname);
				Log::debug(Util::toString($this->fields));
			}else{
				$this->fields[$fname]->constraints = $this->findConstraints($fname);
				if($field->show) $this->allow_show = true;
			}
		}

		//if no primary key, disallow editing
		//otherwise, check validity of fields to edit
		$this->allow_edit = false;
		if(empty($this->pk))
		{
			foreach($this->fields as $fname => $field) $this->fields[$fname]->edit = false;
			Log::trace('DataTable ' . $this->title . ' noticed there is no primary key; setting internal allow_edit to false.');
		}
		else
		{
			foreach($this->fields as $fname => $field)
			{
				if(!$field->edit) continue;
				Log::trace('DataTable ' . $this->title . ' checking edit-validity of field with alias: ' . $field->alias);
				
				if($field->show === false)
				{
					$this->fields[$fname]->edit = false;
					Log::warn('DataTable ' . $this->title . 'asked to edit a field that was not shown: ' . $fname);
					continue;
				}
				if(in_array($fname,$this->pk))
				{
					$this->fields[$fname]->edit = false;
					Log::warn('DataTable ' . $this->title . 'asked to edit a field that was part of a primary key: ' . $fname);
					continue;
				}
				$this->allow_edit = true;
				Log::trace('DataTable ' . $this->title . ' found an editable field; setting internal allow_edit to true.');
			}
		}
		
		//check validity of fields allowed for adding rows
		if($this->allow_add !== true)
		{
			$this->allow_add = false;
			foreach($this->fields as $fname => $field) $this->fields[$fname]->add = false;
		}
		else
		{
			foreach($this->fields as $fname => $fval)
			{
				//remove unshown fields from "add" list
				if($fval->show !== true && $fval->add === true) $this->fields[$fname]->add = false;
				
				//check for fields whose settings will break adding new rows
				if($fval->nullable == 'NO'
					&& (mb_strpos($fval->extra,'auto_increment') === false)
					&& $fval->add === false
					&& $fval->defaultValue === NULL
					&& ($fval->fkReferencedTable === NULL || $fval->fkReferencedField === NULL))
				{
					Log::error('In DataTable ' . $this->title . ' field ' . $fname . ' is set NOT NULL with no auto_increment and no default value here or in the schema, but was configured as not editable in new rows, nor is it a foreign key. Thus adding new rows is not possible.');
					$this->allow_add = false;
					foreach($this->fields as $fieldname => $field)
						$this->fields[$fieldname]->add = false;
					break;
				}
			}
		}
		
		//check deletion field
		if($this->allow_delete !== true && $this->allow_delete !== false)
		{
			if(!array_key_exists($this->allow_delete, $this->fields))
			{
				Log::error('DataTable ' . $this->title . ' specified non existent deletion-tracking field ' . $this->allow_delete);
				$this->allow_delete = false;
			}
		}
		
		//create query builder
		$this->queryBuilder = new QueryBuilder($this->fields, $this->title, $show_querylist);
	}
	
	public function getDb()
	{
		return $this->db;
	}
	
	public function getInPrefix()
	{
		return $this->inPrefix;
	}
	
	/**
	* @return the HTML for the entire DataTable; controls and output
	*/
	protected function getHTMLInternal()
	{
		Log::trace('Getting HTML for dataTable ' . $this->title);
		
		//check preconditions
		if(!$this->setupOK) return '';
		
		//process parameters
		//shown fields
		if(!$this->allow_show)
		{
			Log::warn('DataTable: No fields were selected to be shown');
			return '';
		}

		//get data
		$db = $this->db;
		$query = $this->buildQuery(true);
		$res = $db->query($query, [], 'main data-displaying query for DataTable ' . $this->title);
		if($res === false) return '';
		$query = 'SELECT FOUND_ROWS() AS total';
		$total_res = $db->query($query, [], 'getting total row count for limited fetch in DataTable ' . $this->title);
		if($total_res === false) return '';
		$total = $total_res[0]['total'];
		
		//calculate data
		$pages = ($this->rows_per_page < 1) ? 0 : ceil(((double)$total) / $this->rows_per_page);
		
		//generate html
		$out = '';
		$out .= $this->headerText;
		$pageInput = '<input type="hidden" name="' . $this->inPrefix . 'page' . '" value="' . $this->page . '"/>';
		$out .= '<div class="ki_datatable">';
		
		//query builder
		if($this->show_querybuilder)
		{
			$out .= $this->queryBuilder->getHTML();
		}
		
		//Exports
		if($this->show_exports)
		{
			$exportForm = '<h2 style="margin:1em 3em 1em 0.5em;">Download</h2><form method="post">'
				. '<select name="' . $this->inPrefix . 'format"><option value="CSV">CSV</option><option value="XLSX">Excel</option><option value="ODS">OpenOffice</option></select> '
				. '<input type="submit" name="' . $this->inPrefix . 'export" value="⤓" style="font-size:120%;border:0;width:20px;height:20px;font-weight:bold;padding:0;vertical-align:bottom;position:relative;top:2px;" title="Export"/>'
				. '</form>';
			$exportDrawer = new Drawer($this->inPrefix . 'exportDrawer', $exportForm, Drawer::EDGE_RIGHT, Drawer::DEFAULT_BUTTON . ' Download');
			$out .= ' &nbsp;' . $exportDrawer->getHTML();
		}

		//feedback on previous submit
		$this->outputMessage = array_filter($this->outputMessage);
		$outMsgStr = '<div style="text-align:left;">';
		if(!empty($this->outputMessage))
		{
			$outMsgStr .= '<ul>';
			foreach($this->outputMessage as $retmsg) $outMsgStr .= '<li>' . $retmsg . '</li>';
			$outMsgStr .= '</ul>';
		}
		$outMsgStr .= '</div>';
		$out .= $outMsgStr;
		
		//rows to display and/or edit
		$out .= "\n" . '  <div class="ki_table" id="' . $this->title . '">';
		if($this->rows_per_page > 0) //for add-only forms with no output, skip showing headers. The field names are in the placeholders anyway.
		{
			$headerRow = "\n" . '   <div>';
			//data column headers
			foreach($this->fields as $field)
			{
				if($field->show) $headerRow .= "\n" . '    <div>' . $field->alias . '</div>';
			}
			//add/save/delete button column header
			if($this->allow_edit || $this->allow_add || ($this->allow_delete !== false))
				$headerRow .= "\n" . '    <div class="ki_table_action">&nbsp;</div>';
			//custom callback column header
			if(!empty($this->buttonCallbacks))
				$headerRow .= "\n" . '    <div class="ki_table_action">&nbsp;</div>';
			$headerRow .= "\n" . '   </div>';
			
			$out .= $headerRow;
		}
		$json_data = array();
		foreach($res as $row)
		{
			$fqRow = []; //make copy of row indexed by FQ names instead of alias
			foreach($row as $col => $value)
			{
				$colFQ = $this->alias2fq[$col];
				$fqRow[$colFQ] = $value;
			}
			$dataRow = '';
			//data columns
			foreach($fqRow as $col => $value)
			{
				if(!$this->fields[$col]->show) continue;
				$value = htmlspecialchars($value);
				
				$dataCell = '';
				$cellType = NULL;
				if($this->fields[$col]->edit)
				{
					$cellType = "edit";
					$inputName = $this->inputId($col, $fqRow);
					$inputAttributes = array();
					if($this->fields[$col]->constraints['type'] == 'checkbox')
					{
						$dataCell .= "\n     " . '<input type="hidden" name="' . $inputName . '" id="H' . $inputName . '" value="0"/>';
						$inputAttributes[] = 'value="1"';
						if($value) $inputAttributes[] = 'checked="checked"';
						$json_data[$inputName] = $value ? 1 : 0;
						
						$inputAttributes[] = 'name="' . $inputName . '" id="' . $inputName . '"';
						$inputAttributes[] = $this->stringifyConstraints($col);
						$inputAttributes[] = 'class="ki_table_input"';
						$dataCell .= "\n     " . '<input ' . implode(' ', $inputAttributes) . '/>';
					}
					elseif(Util::startsWith($this->fields[$col]->dataType, 'enum')
						|| (!empty($this->fields[$col]->fkReferencedTable)
							&& !empty($this->fields[$col]->fkReferencedField)
							&& ($this->fields[$col]->dropdownLimit >= $this->fields[$col]->numOptions)))
					{
						$json_data[$inputName] = $value;
						
						$inputAttributes[] = 'name="' . $inputName . '" id="' . $inputName . '"';
						$inputAttributes[] = $this->stringifyConstraints($col, true);
						$inputAttributes[] = 'class="ki_table_input"';
						$dataCell .= "\n     " . '<select ' . implode(' ', $inputAttributes) . '>';
						if($this->fields[$col]->nullable == 'YES')
						{
							$dataCell .= '"\n      "' . '<option'
								. (empty($value) ? ' selected' : '')
								. '></option>';
						}
						foreach($this->fields[$col]->dropdownOptions as $op)
						{
							$dataCell .= "\n      " . '<option'
								. ($value == $op ? ' selected' : '')
								. '>' . $op . '</option>';
						}
						$dataCell .= "\n     " . '</select>';
					}elseif($this->fields[$col]->manyToMany !== false){
						$inputAttributes[] = 'name="' . $inputName . '[]" id="' . $inputName . '"';
						$inputAttributes[] = $this->stringifyConstraints($col);
						$inputAttributes[] = 'class="ki_table_input"';
						
						$valSets = explode(chr(30), $value);
						$valKeys = empty($valSets[0]) ? [] : explode(chr(31), $valSets[0]);
						$valDisp = empty($valSets[1]) ? [] : explode(chr(31), $valSets[1]);
						$json_data[$inputName] = $valKeys;
						
						$dataCell .= "\n     " . '<input type="hidden" name="' . $inputName . '" id="H' . $inputName . '" value=""/>';
						$dataCell .= "\n     ";
						$dataCell .= '<select multiple ' . implode(' ', $inputAttributes) . '>';
						$dataCell .= $this->fields[$col]->manyToMany->optionsHTML($valKeys);
						$dataCell .= '</select>';
					}else{
						if($this->fields[$col]->constraints['type'] == 'datetime-local')
							$value = str_replace(' ', 'T', $value);
						$inputAttributes[] = 'value="' . $value . '" ';
						$json_data[$inputName] = $value;
						
						$inputAttributes[] = 'name="' . $inputName . '" id="' . $inputName . '"';
						$inputAttributes[] = $this->stringifyConstraints($col);
						$inputAttributes[] = 'class="ki_table_input"';
						$dataCell .= "\n     " . '<input ' . implode(' ', $inputAttributes) . '/>';
					}
				}else{
					$cellType = "show";
					if($this->fields[$col]->manyToMany === false)
					{
						$dataCell .= "\n     " . $value;
					}else{
						$valSets = explode(chr(30), $value);
						$valKeys = empty($valSets[0]) ? [] : explode(chr(31), $valSets[0]);
						$valDisp = empty($valSets[1]) ? [] : explode(chr(31), $valSets[1]);
						$dataCell .= "\n     ";
						$dataCell .= '<select disabled multiple>';
						$dataCell .= $this->fields[$col]->manyToMany->optionsHTML($valKeys);
						$dataCell .= '</select>';
					}
				}
				if($this->fields[$col]->outputFilter !== NULL)
				{
					$filterFunc = $this->fields[$col]->outputFilter;
					$dataCell = $filterFunc($dataCell, $cellType, $fqRow);
				}
				if($cellType == 'edit')
				{
					$dataRow .= "\n    " . '<div style="white-space:nowrap;">';
				}else{
					$dataRow .= "\n    " . '<div>';
				}
				if(!empty($this->fields[$col]->callbackButtons))
				{
					foreach($this->fields[$col]->callbackButtons as $index => $cb)
					{
						if(($cb->criteria)($fqRow))
						{
							$buttonName = $this->inputId('0', $fqRow, 'columnCB_' . base64_encode($col) . '-' . $index);
							$dataCell .= ' <input type="submit" name="' . $buttonName . '" id="' . $buttonName . '" class="ki_button_action" value="' . $cb->title . '" formnovalidate />';
						}
					}
				}
				
				$dataRow .= $dataCell;
				$dataRow .= "\n    " . '</div>';
			}
			//add/save/delete buttons
			if($this->allow_edit || $this->allow_add || ($this->allow_delete !== false))
			{
				$dataRow .= "\n" . '    <div class="ki_table_action">' . $pageInput;
				if($this->allow_edit)
				{
					$buttonName = $this->inputId('0', $fqRow, 'submit');
					$dataRow .=  '<input type="submit" name="' . $buttonName . '" id="' . $buttonName . '" value="💾" class="ki_button_save" title="Save" onclick="' . htmlspecialchars($this->eventCallbacks->onclickEdit) . '"/>';
				}
				if($this->allow_edit && ($this->allow_delete !== false))
					$dataRow .= '<span class="ki_noscript_spacer"> - </span>';
				if($this->allow_delete !== false)
				{
					$buttonName = $this->inputId('0', $fqRow, 'delete');
					$dataRow .= '<div class="ki_button_confirm_container">';
					$dataRow .= '<button type="button" id="' . $buttonName . '" class="ki_button_del" title="Delete" style="">❌</button>';
					$dataRow .= '<div class="ki_button_confirm" tabindex="100"><input type="submit" name="' . $buttonName . '" value="Confirm Delete" formnovalidate onclick="' . htmlspecialchars($this->eventCallbacks->onclickDelete) . '"/></div>';
					$dataRow .= '</div>';
				}
				$dataRow .= "\n" . '    </div>';
			}
			//custom callback buttons
			if(!empty($this->buttonCallbacks))
			{
				$dataRow .= "\n" . '    <div class="ki_table_action">' . $pageInput;
				foreach($this->buttonCallbacks as $index => $cb)
				{
					if(!(($cb->criteria)($fqRow))) continue;
					$buttonName = $this->inputId('0', $fqRow, 'callback_'. $index);
					$dataRow .= '<input type="submit" name="' . $buttonName . '" id="' . $buttonName . '" class="ki_button_action" value="' . $cb->title . '" formnovalidate />';
				}
				$dataRow .= "\n" . '    </div>';
			}
			$out .= "\n" . '   <form method="post" action="' . $_SERVER['SCRIPT_NAME'] . '">'
				. ($this->show_querybuilder ? ('<input type="hidden" name="ki_querybuilder_' . $this->title . '_filterResult" value="' . htmlspecialchars($this->queryBuilder->previousResultJSON) . '"/>') : '')
				. $dataRow . "\n" . '   </form>';
		}
		
		//add new row
		if($this->allow_add)
		{
			$out .= "\n   " . '<form method="post" action="' . $_SERVER['SCRIPT_NAME'] . '">';
			foreach($this->fields as $col => $val)
			{
				if(!$val->show) continue;
				$directive = $val->add;
				$out .= "\n    " . '<div>' . "\n     ";
				$addingCell = '';
				if($directive === false)
				{
					if(mb_strpos($val->extra, 'auto_increment') !== false)
					{
						$addingCell .= '[New]';
					}else{
						$addingCell .= $val->defaultValue;
					}
				}
				elseif($directive === true)
				{
					$inputName = $this->inPrefix . 'new_' . base64_encode($this->fields[$col]->fqName(false));
					$inputAttributes = array();
					
					$inputAttributes[] = $this->stringifyConstraints($col);
					if($val->constraints['type'] == 'checkbox')
					{
						$inputAttributes[] = 'name="' . $inputName . '" id="' . $inputName . '"';
						$addingCell .= '<input type="hidden" name="' . $inputName . '" id="H' . $inputName . '" value="0"/>';
						$inputAttributes[] = 'value="1"';
						if($val->defaultValue) $inputAttributes[] = 'checked="checked"';
						$addingCell .= '<input ' . implode(' ', $inputAttributes) . '/>';
					}
					elseif(Util::startsWith($val->dataType, 'enum')
						|| (!empty($val->fkReferencedTable)
							&& !empty($val->fkReferencedField)
							&& ($val->dropdownLimit >= $val->numOptions)))
					{
						$inputAttributes[] = 'name="' . $inputName . '[]" id="' . $inputName . '"';
						$addingCell .= '<select ' . implode(' ', $inputAttributes) . '>';
						if($val->nullable == 'YES')
						{
							$addingCell .= '<option'
								. (empty($val->defaultValue) ? ' selected' : '')
								. '></option>';
						}
						foreach($val->dropdownOptions as $op)
						{
							$addingCell .= '<option'
								. ($val->defaultValue == $op ? ' selected' : '')
								. '>' . $op . '</option>';
						}
						$addingCell .= "\n     " . '</select>';
					}
					elseif($val->manyToMany !== false)
					{
						$inputAttributes[] = 'name="' . $inputName . '[]" id="' . $inputName . '"';
						$defaults = is_array($val->defaultValue) ? $val->defaultValue : [$val->defaultValue];
						$addingCell .= '<input type="hidden" name="' . $inputName . '" id="H' . $inputName . '" value=""/>';
						$addingCell .= '<select multiple ' . implode(' ', $inputAttributes) . '>';
						$addingCell .= $val->manyToMany->optionsHTML($defaults);
						$addingCell .= '</select>';
					}else{
						$inputAttributes[] = 'name="' . $inputName . '" id="' . $inputName . '"';
						$inputAttributes[] = 'value="' . $val->defaultValue . '"';
						$inputAttributes[] = 'placeholder="' . $val->alias . '"';
						$addingCell .= '<input ' . implode(' ', $inputAttributes) . '/>';
					}
				}else{
					$addingCell .= $directive;
				}
				if($this->fields[$col]->outputFilter !== NULL)
				{
					$filterFunc = $this->fields[$col]->outputFilter;
					$addingCell = $filterFunc($addingCell, 'add', $fqRow);
				}
				$out .= $addingCell;
				$out .= "\n    " . '</div>';
			}
			$out .= "\n    " . '<div class="ki_table_action">' . "\n     " . $pageInput . "\n     " . '<input type="submit" title="Add" value="✚" class="ki_button_add" onclick="' . htmlspecialchars($this->eventCallbacks->onclickAdd) . '"/>' . "\n    " . '</div>';
			$out .= "\n   " . '</form>';
		}
		$out .= "\n" . '  </div>';
		
		//paging controls
		if($pages > 1) $out .= "\n  " . $this->pager($pages);
		$out .= '</div>';
		
		//javascript to enhance editing
		if($this->allow_edit)
		{
			$arrIV = $this->inPrefix . 'inputValues';
			$js = '<script>var inputValues = ' . json_encode($json_data) . ';';
			$htmlId = '#' . $this->title;
			if($this->allow_delete)
				$js .= '$("' . $htmlId . ' .ki_button_save").css("position","absolute");';
			$js .= '$("' . $htmlId . ' .ki_noscript_spacer").remove();';
			$js .= '$("' . $htmlId . '.ki_table input,' . $htmlId . '.ki_table select").on("change keydown keyup blur", function(){';
			$js .= 'ki_setEditVisibility($(this).parent().parent().find(".ki_button_save"), inputValues);';
			$js .= '});';
			$js .= '$("' . $htmlId . '.ki_table select[multiple]").multiselect({selectedList:1,header:false});';
			
			$js = str_replace('inputValues',$arrIV,$js);
			$js .= '</script>';
			$out .= $js;
		}
		return $out;
	}
	
	private function buildQuery(bool $limit = true)
	{
		$limit_start = $this->rows_per_page * ($this->page-1);
		$fields = array(); //query snippets listing each real field and its alias if any
		foreach($this->fields as $field)
		{
			if($field->dataType == 'virtual')
			{
				if($field->manyToMany !== false)
				{
					$mainTable                  ='`'.$field->manyToMany->mainTable                  .'`';
					$mainTablePKField           =    $field->manyToMany->mainTablePKFieldFQ();
					$relatedTable               ='`'.$field->manyToMany->relatedTable               .'`';
					$relatedTablePKField        =    $field->manyToMany->relatedTablePKFieldFQ();
					$relatedTableDisplayField   =    $field->manyToMany->relatedTableDisplayFieldFQ();
					$relationTable              ='`'.$field->manyToMany->relationTable              .'`';
					$relationTableRelatedFKField=    $field->manyToMany->relationTableRelatedFKFieldFQ();
					$relationTableMainFKField   =    $field->manyToMany->relationTableMainFKFieldFQ();
					$alias                      ='"'.$field->manyToMany->alias                      .'"';
					
					$fields[] =<<<END_SQL
(
	SELECT CONCAT(
		GROUP_CONCAT($relatedTablePKField ORDER BY $relatedTableDisplayField SEPARATOR 0x1F),
        CHAR(30),
        GROUP_CONCAT($relatedTableDisplayField ORDER BY $relatedTableDisplayField SEPARATOR 0x1F)
	)
    FROM $relationTable LEFT JOIN $relatedTable ON $relationTableRelatedFKField=$relatedTablePKField
    WHERE $relationTableMainFKField=$mainTablePKField
) AS $alias 
END_SQL;
				}
				elseif($field->table == '')
				{
					$fields[] = $field->fqName(false) . ' AS "' . $field->alias . '"';
				}
			}else{
				$fields[] = $field->fqName(true) . ' AS "' . $field->alias . '"';
			}
		}
		
		$sortClause = '';
		$userFilter = NULL;
		if($this->queryBuilder !== NULL && $this->queryBuilder->previousResult !== NULL)
		{
			if(!empty($this->queryBuilder->previousResult->sortOrder))
			{
				$sortClause = [];
				foreach($this->queryBuilder->previousResult->sortOrder as $alias => $direction)
				{
					$sortClause[] = '`' . $alias . '` ' . ($direction == 'A' ? 'ASC' : 'DESC');
				}
				$sortClause = ' ORDER BY ' . implode(',', $sortClause);
			}
			
			$userFilter = $this->queryBuilder->previousResult->getFilterSQL();
		}
		
		$whereClause = [];
		if(!empty($this->filter)) $whereClause[] = '(' . $this->filter . ')';
		if(!empty($userFilter)  ) $whereClause[] = $userFilter;
		$whereClause = implode(' AND ', $whereClause);
		if(!empty($whereClause)) $whereClause = ' WHERE ' . $whereClause;
		
		$fields = implode(',', $fields);
		$query = 'SELECT ' . ($limit?'SQL_CALC_FOUND_ROWS ':'') . $fields
			. ' FROM `' . $this->table . '` ' . $this->joinString . $whereClause . $sortClause;
		if($limit) $query .=  ' LIMIT ' . $limit_start . ',' . $this->rows_per_page;
		return $query;
	}
	
	/**
	* @param col any column name (FQ)
	* @return an array of html5 validation constraints that apply to it including the type attribute
	*/
	protected function findConstraints($col)
	{
		$out = array();

		//schema related
		$colType_without_length = $this->fields[$col]->dataType;
		$type_paren_loc = mb_strpos($this->fields[$col]->dataType,'(');
		if($type_paren_loc !== false)
		{
			$colType_without_length = mb_substr($this->fields[$col]->dataType,0,$type_paren_loc);
		}
		
		$htmlType = $this->formType($this->fields[$col]->dataType);
		$out['type'] = $htmlType;
	
		if($this->fields[$col]->nullable == 'NO' && $this->fields[$col]->extra != 'auto_increment' && $this->fields[$col]->defaultValue === NULL && $htmlType != 'checkbox')
		{
			$out['required'] = NULL;
		}
		
		if(in_array($colType_without_length, $this->textTypes))
		{
			$reg_matches = array();
			$regex = '/\((\d+)\)$/';
			$reg_result = preg_match($regex,$this->fields[$col]->dataType, $reg_matches);
			if($reg_result == 1)
			{
				Log::trace('Found maxlength');
				$out['maxlength'] = $reg_matches[1];
				if($out['maxlength'] < 20) $out['size'] = $reg_matches[1];
			}
		}
		
		if(mb_strpos($this->fields[$col]->dataType, 'unsigned') !== false)
		{
			$out['min'] = 0;
		}

		//setup related
		if(!is_array($this->fields[$col]->constraints))
		{
			$this->fields[$col]->constraints = array();
			Log::warn('DataTable ' . $this->title . ' had invalid constraints for field: ' . $col);
		}
		$out = array_merge($out,$this->fields[$col]->constraints);
		
		return $out;
	}
	
	/**
	* @param col a column name (FQ)
	* @return constraints for column in HTML form ready to be inserted into an input
	*/
	protected function stringifyConstraints($col, $isDropdown = false)
	{
		$out = '';
		foreach($this->fields[$col]->constraints as $cname => $cval)
		{
			if($isDropdown && $cname="type") continue;
			$out .= $cname;
			if($cval !== NULL) $out .= '="' . $cval . '"';
			$out .= ' ';
		}
		return $out;
	}
	
	/**
	* Handle HTTP params (GET, POST) and update the database if necessary
	* @return boolean whether any items were successfully processed.
	*/
	protected function handleParamsInternal()
	{
		Log::trace('Handling params for DataTable ' . $this->title);
		$db = $this->db;
		$didSomething = false;
		
		//check preconditions
		if(!$this->setupOK) return false;
		
		//interpret arguments
		$post = $this->post;
		$get  = $this->get;
		
		//set state from params
		$shouldCheckPost = true;
		if(isset($get[$this->inPrefix . 'page']) && empty($post))
		{
			Log::trace('DataTable ' . $this->title . ' found GET and no POST');
			$this->page = (int)$get[$this->inPrefix . 'page'];
			$shouldCheckPost = false; //if GET was used, don't process POST
		}
		
		if($shouldCheckPost)
		{
			if(isset($post[$this->inPrefix . 'page'])) $this->page = (int)$post[$this->inPrefix . 'page'];
			
			//interpret and verify edits to save
			$editPrefix = $this->inPrefix . 'edit_';
			$changesToSave = array();
			$newPrefix = $this->inPrefix . 'new_';
			$newRow = array();
			$deletePrefix = $this->inPrefix . 'delete_';
			$deleteKeys = array();
			$callbackPrefix = $this->inPrefix . 'callback_';
			$columnCallbackPrefix = $this->inPrefix . 'columnCB_';

			foreach($post as $key => $value)
			{
				if(mb_strpos($key,$deletePrefix) === 0) //check for delete button
				{
					if($this->allow_delete === false)
					{
						Log::warn('DataTable: Tried to delete a row but deleting is not allowed');
						continue;
					}
					elseif($this->allow_delete !== true && !isset($this->fields[$this->allow_delete]))
					{
						Log::error("DataTable: Tried to delete a row but deactivation field doesn't exist");
						continue;
					}
					$key = mb_substr($key,mb_strlen($deletePrefix));
					$key = base64_decode($key);
					if($key === false)
					{
						Log::warn('DataTable delete button failed base64 decoding');
						continue;
					}
					$key = json_decode($key, true);
					if($key === NULL)
					{
						Log::warn('DataTable delete button failed json decoding');
						continue;
					}
					if(count($key) != 2)
					{
						Log::warn('DataTable delete button had wrong number of root elements');
						continue;
					}
					$pk_values = $key[1];
					if(count($pk_values) != count($this->pk))
					{
						Log::warn('DataTable delete button had wrong number of primary key values');
						continue;
					}
					foreach($pk_values as $col => $val)
					{
						if(!in_array($this->fields[$col]->name, $this->pk))
						{
							Log::warn('DataTable delete button had key fields not part of the primary key.');
							continue 2;
						}
					}
					$deleteKeys[] = $pk_values;
				}
				elseif(mb_strpos($key,$callbackPrefix) === 0) //check for row callback button
				{
					$cbTitle = $value;
					$key = mb_substr($key,mb_strlen($callbackPrefix));
					$cbIndex = mb_substr($key,0,mb_strpos($key,'_'));
					$key = mb_substr($key,mb_strlen($cbIndex)+1);
					
					$key = base64_decode($key);
					if($key === false)
					{
						Log::warn('DataTable callback button failed base64 decoding');
						continue;
					}
					$key = json_decode($key, true);
					if($key === NULL)
					{
						Log::warn('DataTable callback button failed json decoding');
						continue;
					}
					if(count($key) != 2)
					{
						Log::warn('DataTable callback button had wrong number of root elements');
						continue;
					}
					$pk_values = $key[1];
					if(count($pk_values) != count($this->pk))
					{
						Log::warn('DataTable callback button had wrong number of primary key values');
						continue;
					}
					if(!isset($this->buttonCallbacks[$cbIndex]))
					{
						Log::warn('DataTable callback button specified invalid function/name: ' . $cbIndex . ':' . $cbTitle . ',' . $cbFunc);
						continue;
					}
					$pkNamed = array();
					foreach($pk_values as $fqName => $val)
					{
						$name = $this->fields[$fqName]->name;
						$pkNamed[$name] = $val;
					}
					$cbMsg = ($this->buttonCallbacks[$cbIndex]->func)($pkNamed);
					if(!empty($cbMsg)) $this->outputMessage[] = $cbMsg;
				}
				elseif(mb_strpos($key,$columnCallbackPrefix) === 0) //check for column callback button
				{
					$cbTitle = $value;
					$key = mb_substr($key,mb_strlen($columnCallbackPrefix));
					$cbIndexPair = mb_substr($key,0,mb_strpos($key,'_'));
					$key = mb_substr($key,mb_strlen($cbIndexPair)+1);
					
					$cbIndexPair = explode('-',$cbIndexPair);
					$cbCol = base64_decode($cbIndexPair[0]);
					$cbIndex = $cbIndexPair[1];
					
					$key = base64_decode($key);
					if($key === false)
					{
						Log::warn('DataTable column callback button failed base64 decoding');
						continue;
					}
					$key = json_decode($key, true);
					if($key === NULL)
					{
						Log::warn('DataTable column callback button failed json decoding');
						continue;
					}
					if(count($key) != 2)
					{
						Log::warn('DataTable column callback button had wrong number of root elements');
						continue;
					}
					$pk_values = $key[1];
					if(count($pk_values) != count($this->pk))
					{
						Log::warn('DataTable column callback button had wrong number of primary key values');
						continue;
					}
					if(!isset($this->fields[$cbCol]->callbackButtons[$cbIndex]))
					{
						Log::warn('DataTable column callback button specified invalid function/name: ' . $cbIndex . ':' . $cbTitle . ',' . $cbFunc);
						continue;
					}
					$pkNamed = array();
					foreach($pk_values as $fqName => $val)
					{
						$name = $this->fields[$fqName]->name;
						$pkNamed[$name] = $val;
					}
					$cbMsg = ($this->fields[$cbCol]->callbackButtons[$cbIndex]->func)($pkNamed);
					if(!empty($cbMsg)) $this->outputMessage[] = $cbMsg;
				}
				elseif(mb_strpos($key,$editPrefix) === 0) //check for edit input
				{
					$key = substr($key,mb_strlen($editPrefix));
					$key = base64_decode($key);
					if($key === false)
					{
						Log::warn('DataTable edit parameter failed base64 decoding');
						continue;
					}
					$key = json_decode($key, true);
					if($key === NULL)
					{
						Log::warn('DataTable edit parameter failed json decoding');
						continue;
					}
					if(count($key) != 2)
					{
						Log::warn('DataTable edit parameter had wrong number of root elements');
						continue;
					}
					$col = $key[0];
					$pk_values = $key[1];
					if(!isset($post[$this->inputId('0', $pk_values, 'submit')])) continue; //skip if save wasn't clicked
					if(count($pk_values) != count($this->pk))
					{
						Log::warn('DataTable edit parameter had wrong number of primary key values');
						continue;
					}
					$pk_values = json_encode($pk_values);
					if(!$this->fields[$col]->edit)
					{
						Log::warn('Tried to edit non-editable field');
						continue;
					}
					if(!isset($changesToSave[$pk_values])) $changesToSave[$pk_values] = array();
					$changesToSave[$pk_values][$col] = $value;
				}
				elseif(mb_strpos($key,$newPrefix) === 0) //check for new row input
				{
					$col = base64_decode(mb_substr($key,mb_strlen($newPrefix)));
					if($this->fields[$col]->add !== true)
					{
						Log::warn('(DataTable) Tried to specify value for new row in field not editable in new rows');
						continue;
					}
					$newRow[$col] = $value;
				}
			}
			
			//handle deletes
			foreach($deleteKeys as $pk)
			{
				$query = '';
				if($this->allow_delete === true)
				{
					$query = 'DELETE FROM `' . $this->table . '` WHERE ';
				}else{
					$query = 'UPDATE `' . $this->table . '` SET `' . $this->allow_delete . '`=0 WHERE ';
				}
				$conditions = array();
				foreach($pk as $col => $value)
				{
					if(mb_strpos($this->fields[$col]->dataType,'int') !== false)
					{
						$value = (int)$value;
					}else{
						$value = '"' . $db->esc($value) . '"';
					}
					$conditions[] = $this->fields[$col]->fqName(true) . '=' . $value;
				}
				$conditions[] = $this->filter; //this line only allows deleting rows which match the filter
				$query .= implode(' AND ', $conditions) . ' LIMIT 1;';
				
				if(isset($this->eventCallbacks->beforeDelete))
				{
					$cbFunc = $this->eventCallbacks->beforeDelete;
					$cbRes = $cbFunc($pk);
					if($cbRes !== true)
					{
						$this->outputMessage[] = $cbRes;
						continue;
					}
				}
				
				$res = $db->query($query, [], 'deleting row via DataTable ' . $this->title);
				if($res === false)
				{
					$this->outputMessage[] = 'Failed to delete row ' . htmlspecialchars(implode(',',$pk));
				}else{
					if($res == 0)
					{
						$this->outputMessage[] = 'Could not find row ' . htmlspecialchars(implode(',',$pk));
					}else{
						$this->outputMessage[] = 'Successfully ' . (($this->allow_delete === true) ? 'deleted' : 'disabled') . ' row ' . htmlspecialchars(implode(',',$pk));
						$didSomething = true;
						if(isset($this->eventCallbacks->onDelete))
						{
							$cbFunc = $this->eventCallbacks->onDelete;
							$msg_onDelete = $cbFunc($pk);
							if(!empty($msg_onDelete)) $this->outputMessage[] = $msg_onDelete;
						}
					}
				}
			}
			
			//query for saving edits
			foreach($changesToSave as $pk => $vals) //for each row
			{
				Log::trace('Checking changes for row ' . $pk);
				$pk = json_decode($pk, true);
				$query = 'UPDATE `' . $this->table . '` ' . $this->joinString . 'SET ';
				$setVals = array();

				if(isset($this->eventCallbacks->beforeEdit))
				{
					$cbFunc = $this->eventCallbacks->beforeEdit;
					$cbRes = $cbFunc($vals);
					if($cbRes !== true)
					{
						$this->outputMessage[] = $cbRes;
						continue;
					}
				}

				foreach($vals as $col => $value) //for each field in the row
				{
					Log::trace('Checking field ' . $col);
					$alias = $this->fields[$col]->alias;
					$constraintsPass = true;
					foreach($this->fields[$col]->constraints as $cname => $cval) //for each html5 constraint applying to the column
					{
						Log::trace('Checking constraint ' . $cname);
						$conres = $this->checkConstraint($cname, $cval, $value, $alias);
						if($conres !== true)
						{
							$constraintsPass = false;
							$this->outputMessage[] = $conres;
							break;
						}
					}
					if(!$constraintsPass) continue;
					Log::trace('Constraints passed.');
					
					$updatedVirtualField = false;
					if($this->fields[$col]->dataType != 'virtual')
					{
						if($value == "")
						{
							$value = 'NULL';
						}else{
							if(mb_strpos($this->fields[$col]->dataType,'int') !== false)
							{
								$value = (int)$value;
							}else{
								$value = '"' . $db->esc($value) . '"';
							}
						}
						$setVals[] = $this->fields[$col]->fqName(true) . '=' . $value;
					}elseif($this->fields[$col]->manyToMany !== false){
						$updatedVirtualField = true;
						
						$res = $this->fields[$col]->manyToMany->updateData($pk, $value, $db);
						if($res !== true)
						{
							$this->outputMessage[] = $res;
						}
					}
				}

				$query .= implode(',', $setVals) . ' WHERE ';
				$conditions = array();
				foreach($pk as $col => $value)
				{
					if(mb_strpos($this->fields[$col]->dataType,'int') !== false)
					{
						$value = (int)$value;
					}else{
						$value = '"' . $db->esc($value) . '"';
					}
					$conditions[] = $this->fields[$col]->fqName(true) . '=' . $value;
				}
				$conditions[] = $this->filter; //this line only allows editing rows which match the filter
				$query .= implode(' AND ', $conditions);
				if(empty($this->joinTables)) $query .= ' LIMIT 1;'; //Safety limit can only be used in single table mode per mysql syntax
				
				/*
					If there was nothing to put in the SET clause,
					don't attempt to run the UPDATE as it will fail anyway.
					This situation could happen if only a many-to-many relation is being updated.
				*/
				if(!empty($setVals))
					$res = $db->query($query, [], 'updating row for DataTable ' . $this->title);
				else
					$res = 0;
				
				if($res === false)
				{
					$this->outputMessage[] = 'Failed to update row ' . htmlspecialchars(implode(',',$pk));
				}else{
					if($res == 0 && !$updatedVirtualField)
					{
						$this->outputMessage[] = 'Could not find editable row for ' . htmlspecialchars(implode(',',$pk)) . ' or nothing was edited.';
					}else{
						$this->outputMessage[] = 'Successfully updated row ' . htmlspecialchars(implode(',',$pk));
						$didSomething = true;
						if(isset($this->eventCallbacks->onEdit))
						{
							$cbFunc = $this->eventCallbacks->onEdit;
							$msg_onEdit = $cbFunc($pk);
							if(!empty($msg_onEdit)) $this->outputMessage[] = $msg_onEdit;
						}
					}
				}
			}
			
			//handle saving new row
			if(!empty($newRow))
			{
				//include defaults for fields not specified
				foreach($this->fields as $col => $fval)
				{
					$directive = $fval->add;
					if(!array_key_exists($col, $newRow))// || empty($newRow[$col]))
					{
						if($directive !== true && $directive !== false)
						{
							$newRow[$col] = $directive;
						}
					}
				}
				
				//validate
				$constraintsPass = true;
				foreach($newRow as $col => $value)
				{
					$alias = $this->fields[$col]->alias;
					foreach($this->fields[$col]->constraints as $cname => $cval)
					{
						$conres = $this->checkConstraint($cname, $cval, $value, $alias);
						if($conres !== true)
						{
							$constraintsPass = false;
							$this->outputMessage[] = $conres;
							break;
						}
					}
				}
				if(isset($this->eventCallbacks->beforeAdd))
				{
					$cbFunc = $this->eventCallbacks->beforeAdd;
					$cbRes = $cbFunc($newRow);
					if($cbRes !== true)
					{
						$constraintsPass = false;
						$this->outputMessage[] = $cbRes;
					}
				}
				
				//build query
				if($constraintsPass)
				{
					$query = 'INSERT INTO ' . $this->table . ' SET ';
					$setters = array();
					$pk = array();
					$virtuals = [];
					foreach($newRow as $col => $value)
					{
						$colname = $this->fields[$col]->name;
						$tablename = $this->fields[$col]->table;
						if(in_array($colname,$this->pk)) $pk[$col] = $value;
						
						if($this->fields[$col]->dataType == 'virtual')
						{
							$virtuals[$col] = $value;
						}
						elseif($this->fields[$col]->table != $this->table)
						{
							//find the corresponding joinTable
							$setterField = NULL;
							$referencedUniqueField = NULL;
							$realJoinTableName = '';
							foreach($this->joinTables as $joinTable)
							{
								if($joinTable->joinTableAlias == $this->fields[$col]->table)
								{
									$setterField = $joinTable->mainTableFKField;
									$realJoinTableName = $joinTable->joinTable;
									$referencedUniqueField = $joinTable->joinTableReferencedUniqueField;
									break;
								}
							}
							if($setterField === NULL)
							{
								Log::error('DataTable new row creation recieved value for a field in an ancillary table that it did not find in the join list: ' . $this->fields[$col]->table);
								continue;
							}
							//first, create new row in other tables for otherwise unresolvable foreign keys
							//or use existing value if it's already there
							$otherTableSearchQuery = 'SELECT `' . $referencedUniqueField . '` AS keyVal '
								. 'FROM `' . $realJoinTableName . '` WHERE `' . $this->fields[$col]->name
								. '`=?';
							$searchRes = $db->query($otherTableSearchQuery, [$value], 'searching for reusable value in ancillary table');
							$setterVal = NULL;
							if(empty($searchRes))
							{
								$otherTableAddQuery = 'INSERT INTO `' . $realJoinTableName
									. '` SET `' . $this->fields[$col]->name . '`=?';
								$otRes = $db->query($otherTableAddQuery, [$value], 'adding row to non-main table to satisfy foreign key');
								if($otRes === false) continue;
								$setterVal = $db->connection->insert_id;
							}else{
								$setterVal = $searchRes[0]['keyVal'];
							}
							$setter = '`' . $setterField . '`=' . $setterVal;
							$setters[] = $setter;
						}else{
							$setter = '`' . $tablename . '`.`' . $colname . '`=';
							if($value === NULL)
							{
								$setter .= 'NULL';
							}else{
								if(mb_strpos($this->fields[$col]->dataType,'int') !== false)
								{
									$setter .= (int)$value;
								}else{
									$setter .= '"' . $db->esc($value) . '"';
								}
							}
							$setters[] = $setter;
						}
					}

					$query .= implode(',', $setters);
					$res = $db->query($query, [], 'adding new row');
					if($res === false)
					{
						$err = $db->connection->error;
						$this->outputMessage[] = 'Error adding new row: ' . $err;
					}else{
						$insert_id = $db->connection->insert_id;
						$message = 'New row added successfully';
						if($insert_id != 0)
						{
							$message .= ' with number ' . $insert_id;
							$pk[$this->autoCol] = $insert_id;
						}
						
						foreach($virtuals as $col => $value)
						{
							$res = $this->fields[$col]->manyToMany->updateData($pk, $value, $db);
							if($res !== true)
							{
								$this->outputMessage[] = $res;
							}
						}
						
						$didSomething = true;
						$addedToOutputMessage = false;
						if(isset($this->eventCallbacks->onAdd))
						{
							$cbFunc = $this->eventCallbacks->onAdd;
							$msg_onAdd = $cbFunc($pk);
							if(!empty($msg_onAdd))
							{
								$this->outputMessage[] = $msg_onAdd;
								$addedToOutputMessage = true;
							}
						}
						if(!$addedToOutputMessage) $this->outputMessage[] = $message;
					}
				}
			}
		}

		//take care of queryBuilder
		if($this->show_querybuilder)
		{
			$this->queryBuilder->handleParams($post, $get);
	
			if($this->queryBuilder->previousResult !== NULL && !empty($this->queryBuilder->previousResult->fieldsToShow))
			{
				$newFields = [];
				$index = 0;
				foreach($this->queryBuilder->previousResult->fieldsToShow as $alias)
				{
					$fq = $this->alias2fq[$alias];
					$newFields[$fq] = $this->fields[$fq];
					++$index;
					
					//For "showable but not by default" fields, show if selected in the querybuilder
					if($newFields[$fq]->show === NULL)
					{
						$newFields[$fq]->show = true;
					}
				}
				$this->fields = $newFields;
			}
		}
		
		//Export immediately triggers output and halt
		if($this->show_exports
			&& isset($post[$this->inPrefix.'export'])
			&& method_exists('\mls\ki\Exporter',$post[$this->inPrefix.'format']))
		{
			$extension = '';
			$query = $this->buildQuery(false);
			$data = $db->query($query, [], 'getting data for export by DataTable');
			if(!empty($data)) array_unshift($data, array_keys($data[0]));
			switch($post[$this->inPrefix.'format'])
			{
				case 'CSV':
				$data = Exporter::CSV($data, true);
				$extension = 'csv';
				break;
				
				case 'XLSX':
				$data = Exporter::XLSX($data);
				$extension = 'xlsx';
				break;
				
				case 'ODS':
				$data = Exporter::ODS($data);
				$extension = 'ods';
				break;
			}
			if(!empty($extension))
			{
				header('Content-Type: application/octet-stream');
				header('Content-Transfer-Encoding: Binary');
				header('Content-disposition: attachment; filename="' . $this->table . '.' . $extension . '"');
				echo $data;
				exit;
			}
		}
		
		return $didSomething;
	}
	
	/**
	* Perform server side check using HTML5-style constraints
	* @param $cname name of the constraint
	* @param $cval value of the constraint
	* @param $value the input value being checked against the constraint
	* @param $alias the field name of the value; used only in the error string
	* @return boolean true on success, or error string
	*/
	protected function checkConstraint($cname, $cval, $value, $alias)
	{
		if($cname == 'type')
		{
			return true; //we'll handle the type when building the sql
		}
		elseif($cname == 'size')
		{
			return true; //not really a constraint
		}
		elseif($cname == 'required')
		{
			if($value == "")
			{
				return 'Can not blank required field ' . $alias;
			}
		}
		elseif($cname == 'maxlength')
		{
			if(mb_strlen($value) > $cval)
			{
				return 'Input greater than max length for field ' . $alias;
			}
		}
		elseif($cname == 'min')
		{
			if($value < $cval)
			{
				return 'Input less than minimum (' . $cval .') for field ' . $alias;
			}
		}
		elseif($cname == 'max')
		{
			if($value > $cval)
			{
				return 'Input greater than maximum (' . $cval .') for field ' . $alias;
			}
		}
		elseif($cname == 'pattern')
		{
			$preg_result = preg_match('/'.$cval.'/',$value);
			if($preg_result != 1)
			{
				return "Input doesn't match the pattern (" . $cval .') for field ' . $alias;
			}
		}
		return true;
	}
	
	/**
	* Produce the html paging controls so the user can navigate the data
	* @param pages The number of pages to generate for
	* @return the paging controls as HTML
	*/
	protected function pager($pages)
	{
		$out = '<div style="text-align:center;display:inline-block;">';
		$pageList = Util::pagesToShow($this->page,$pages);
		$pageParam = $this->inPrefix . 'page';
		
		$queryString = $this->show_querybuilder ? ('&amp;ki_querybuilder_' . $this->title . '_filterResult=' . urlencode($this->queryBuilder->previousResultJSON)) : '';
		
		//first row: arrows and direct page input
		$out .= '<span style="float:left;">';
		if($this->page > 1)
		{
			$out .= '<a href="?' . $pageParam . '=1' . $queryString . '">⇤</a> &nbsp; ';
			$out .= '<a href="?' . $pageParam . '=' . ($this->page - 1) . $queryString . '">⬅</a> &nbsp; ';
		}else{
			$out .= '⇤ &nbsp; ';
			$out .= '⬅ &nbsp; ';
		}
		$out .= '</span>';
		$out .= '<form method="get" style="display:inline-block;margin:0;">'
			. '<input name="' . $pageParam . '" '
				. 'type="number" min="0" max="' . $pages . '" value="' . $this->page . '" '
				. 'size="5" style="width:4em;"/>'
			. '<input type="submit" name="go" value="Page"/>'
			. ($this->show_querybuilder ? ('<input type="hidden" name="ki_querybuilder_' . $this->title . '_filterResult" value="' . htmlspecialchars($this->queryBuilder->previousResultJSON) . '"/>') : '')
			. '</form> &nbsp; ';
		
		$out .= '<span style="float:right;">';
		if($this->page < $pages)
		{
			$out .= '<a href="?' . $pageParam . '=' . ($this->page + 1) . $queryString . '">➡</a> &nbsp; ';
			$out .= '<a href="?' . $pageParam . '=' . $pages . $queryString . '">⇥</a>';
		}else{
			$out .= '➡ &nbsp; ';
			$out .= '⇥';
		}
		$out .= '</span><br/>';
		
		//second row: search engine style page number links
		$last = 1;
		foreach($pageList as $pnum)
		{
			if($pnum > ($last + 1)) $out .= '…&nbsp;&nbsp;&nbsp;';
			if($pnum == $this->page)
			{
				$out .= $pnum;
			}else{
				$out .= '<a href="?' . $pageParam . '=' . $pnum
					. $queryString
					. '">' . $pnum . '</a>';
			}
			if($pnum != $pages) $out .= '&nbsp;&nbsp;&nbsp;';
			$last = $pnum;
		}
		
		$out .= '</div>';
		return $out;
	}
	
	/**
	* Make a string identifying an input, using the following pieces:
	* 1. The input prefix for the table
	* 2. The word "edit" or likewise for other actions
	* 3. A string encoding the column name and the values of all the
	*    primary-key-constituent columns in this row. This is essentially a
	*    single serialized value for the PK even when the row has a compound PK
	* @param fieldBeingEdited The column name of the value that the input will be for (FQ)
	* @param row An associative array of all values in the corresponding row (FQ indexed)
	* @param type The type of input; edit/add
	* @return a string to use as the ID of the html input
	*/
	protected function inputId($fieldBeingEdited, $row, $type='edit')
	{
		foreach($row as $colFQ => $value)
		{
			$colname = $this->fields[$colFQ]->name;
			$table = $this->fields[$colFQ]->table;
			if($table != $this->table || !in_array($colname, $this->pk))
			{
				unset($row[$colFQ]);
			}
		}
		return $this->inPrefix . htmlspecialchars($type) . '_'
			. str_replace('=','',base64_encode(json_encode([$fieldBeingEdited,$row])));
	}
	
	/**
	* @param type a MySQL field type
	* @return the corresponding HTML input type
	*/
	protected function formType($type)
	{
		if($type == 'tinyint(1)') return 'checkbox';
		if(mb_strpos($type, 'int') !== false) return 'number';
		if(mb_strpos($type, 'datetime') !== false) return 'datetime-local';
		if(mb_strpos($type, 'date') !== false) return 'date';
		
		return 'text';
	}
}

?>