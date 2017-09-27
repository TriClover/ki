<?php
namespace mls\ki\Widgets;
use \mls\ki;
use \mls\ki\Database;
use \mls\ki\Log;
use \mls\ki\Util;

/**
* DataTable - generic data manipulation and reporting tool.
* Editing only works when there is a primary key on the specified table(s)
*/
class DataTable
{
	//setup parameters
	protected $title;            //title for this widget - needed to separate multiple DataTables on the same page
	protected $table;            //name of table to use. If array, will use first as base and the rest will be LEFT JOINed in on the first foreign key found. For tables with no direct foreign key it will look for a many-to-many-relation table named firsttable_othertable with appropriate foreign keys.
	protected $fields;           //array of DataTableField objects specifying what to do with each field. Fields not specified will get the defaults shown in the DataTableField class. A field with NULL for the name overrides what is used for fields not specified.
	protected $allow_add;        //true: allow adding rows. false: dont. array: allow adding rows but only allow input for the given fields. Disallowed fields that are NOT NULL and don't have a default value here or in the schema will cause adding to be disallowed entirely. Numeric keys will interpret the value as a field name to allow. String keys will interpret the key as the field name and the value as the directive for that field, where true=allow editing, false=disallow and use default/auto value, and string or number=disallow and use this value instead. fields not in $fields_show are ignored.
	protected $allow_delete;     //true: allow deleting rows. false: don't. string: allow but set this field=false instead of actually deleting
	protected $filter;           //if string, use as sql fragment in where clause to filter output: useful if table has "enabled" field.
	protected $rows_per_page;
	protected $show_exports;     //true: show all export buttons. false: don't. array: show only specified formats (xlsx,xls,csv,xml,json,sql)
	protected $show_querybuilder;//allow filtering, sorting, etc
	protected $show_querylist;   //allow loading queries. if $show_querybuilder is false you only see the results and not the conditions
	protected $show_querysave;   //allow saving queries. Only works if $show_querybuilder and $show_querylist are true.
	protected $eventCallbacks;   //DataTableEventCallbacks object
	protected $buttonCallbacks;  //array mapping titles to function names that get their own buttons in the table for each row. They recieve the PK.
	
	//setup, calculated
	protected $inPrefix;              //prefix of all HTML input names
	protected $customCallbacks = array();
	protected $allow_edit;
	protected $allow_show;
	
	//schema
	protected $pk = array();   //fields that are in the primary key
	protected $autoCol = NULL; //field with auto_increment, NULL if none
	
	//state from params
	protected $page = 1;
	
	//state, internal
	protected $printed       = false;   //The HTML has been generated. This helps detect improper usage
	protected $handledParams = false;   //The HTTP params (get/post) have been handled. This prevents doing the DB operations multiple times.
	protected $setupOK       = true;    //Whether the setup was successful. If false, no other features will try to do anything.
	protected $outputMessage = array();
	static $anyPrinted       = false;   //Whether any DataTable object has generated its HTML. Only the first one will contain stuff that only needs to be printed once.
	
	//const
	protected $textTypes = array('email', 'password', 'search', 'tel', 'text', 'url'); //html form types where attributes like "size" and "maxlength" are valid
	
	function __construct($title,
	                     $table,                     //todo: support multiple
	                     $fields            = array(),
	                     $allow_add         = false,
	                     $allow_delete      = false,
	                     $filter            = false,
	                     $rows_per_page     = 50,
	                     $show_exports      = false, //todo
	                     $show_querybuilder = false, //todo
	                     $show_querylist    = false, //todo
	                     $show_querysave    = false, //todo
						 $eventCallbacks    = NULL,
						 $buttonCallbacks   = NULL)
	{
		//save parameters
		$this->title             = $title;
		$this->table             = $table;
		$this->allow_add         = $allow_add;
		$this->allow_delete      = $allow_delete;
		$this->filter            = mb_strlen($filter) > 0 ? $filter : '1';
		$this->rows_per_page     = $rows_per_page;
		$this->show_exports      = $show_exports;
		$this->show_querybuilder = $show_querybuilder;
		$this->show_querylist    = $show_querylist;
		$this->show_querysave    = $show_querysave;
		$this->eventCallbacks    = $eventCallbacks;
		$this->buttonCallbacks   = $buttonCallbacks;
		
		//calculate more setup info
		$this->inPrefix = 'ki_datatable_' . htmlspecialchars($title) . '_';
		
		//process field definitions
		$defaultField = new DataTableField(NULL);
		if($fields === NULL) $fields = array();
		if(!is_array($fields))
		{
			Log::error('DataTable ' . $this->title . ' "fields" was not array or NULL.');
			$this->setupOK = false;
			return;
		}
		foreach($fields as $inputField)
		{
			if($inputField->name === NULL)
			{
				//field config for "NULL" will be used for any fields where a config was not provided
				$defaultField = $inputField;
			}else{
				//store the given field config in the DataTable indexed by field name
				$this->fields[$inputField->name] = $inputField;
				if($inputField->table === NULL)
					$this->fields[$inputField->name]->table = $this->table;
			}
		}
		
		//get schema info
		$db = Database::db()->connection;
		$query = 'SHOW COLUMNS FROM `' . $this->table . '`';
		$res = $db->query($query);
		if($res === false)
		{
			Log::error('Getting schema info failed for dataTable ' . $this->title
				. ' with error ' . $db->errno . ': ' . $db->error . ' -- ' . $query);
			$setupOK = false;
			return;
		}
		while($row = $res->fetch_assoc())
		{
			//if this field isn't in the config list, add it with default settings
			if(!array_key_exists($row['Field'], $this->fields))
			{
				Log::trace('DataTable ' . $this->title . ' applying default values for unconfigured field: ' . $row['Field']);
				$this->fields[$row['Field']] = clone $defaultField;
				$this->fields[$row['Field']]->name = $row['Field'];
				$this->fields[$row['Field']]->determineAlias();
				$this->fields[$row['Field']]->table = $this->table;
				//Exception: Never try to enable editing on PK fields
				if($row['Key'] == 'PRI') $this->fields[$row['Field']]->edit = false;
			}
			//Don't accept the default of allowing user value for add on auto_increment column
			if(mb_strpos($row['Extra'],'auto_increment') !== false)
			{
				$this->fields[$row['Field']]->add = false;
			}
				
			//store schema info in the field config
			$this->fields[$row['Field']]->dataType     = $row['Type'];
			$this->fields[$row['Field']]->nullable     = $row['Null'];
			$this->fields[$row['Field']]->keyType      = $row['Key'];
			$this->fields[$row['Field']]->defaultValue = $row['Default'];
			$this->fields[$row['Field']]->extra        = $row['Extra'];
			
			//keep track of which fields have certain properties so we're not searching later
			if($row['Key'] == 'PRI') $this->pk[] = $row['Field'];
			if(mb_strpos($row['Extra'],'auto_increment') !== false) $this->autoCol = $row['Field'];
		}
		
		//check schema and setup
		
		//check validity of fields to show, add auto generated constraints
		$this->allow_show = false;
		foreach($this->fields as $fname => $field)
		{
			if($field->dataType === NULL)
			{
				unset($this->fields[$fname]);
				Log::warn('DataTable ' . $this->title . ' asked to show a field that is not in the table: ' . $fname);
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
				
				if(!$field->show)
				{
					$this->fields[$fname]->edit = false;
					Log::warn('DataTable ' . $this->title . 'asked to edit a field that was not shown: ' . $fname);
					continue;
				}
				if(in_array($fname,$this->pk))
				{
					$this->fields[$fname]->edit = false;
					Log::warn('DataTable ' . $this->title . 'asked to edit a field that was part of the primary key: ' . $fname);
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
				if(!$fval->show) $this->fields[$fname]->add = false;
				
				//check for fields whose settings will break adding new rows
				if($fval->nullable == 'NO'
					&& (mb_strpos($fval->extra,'auto_increment') === false)
					&& $fval->add === false
					&& $fval->defaultValue === NULL)
				{
					Log::error('In DataTable . ' . $this->title . ' field ' . $fname . ' is set NOT NULL with no auto_increment, but was configured as not editable in new rows, and has no default value here or in the schema. Thus adding new rows is not possible.');
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
				Log::error('DataTable ' . $title . ' specified non existent deletion-tracking field ' . $this->allow_delete);
				$this->allow_delete = false;
			}
		}
	}
	
	function getHTML()
	{
		Log::trace('Getting HTML for dataTable');
		
		//check preconditions
		if(!$this->setupOK) return '';
		if($this->printed)
		{
			Log::warn('Generated HTML for the same DataTable twice in one page load. This is bad for performance.');
		}
		$this->printed = true;
		if(!$this->handledParams)
		{
			Log::warn('DataTable generating HTML without having handled params. This may cause usability issues.');
		}
		
		//process state info
		$limit_start = $this->rows_per_page * ($this->page-1);
		
		//process parameters
		//shown fields
		if(!$this->allow_show)
		{
			Log::warn('DataTable: No fields were selected to be shown');
			return '';
		}
		$fields = array(); //query snippets listing each real field and its alias if any
		foreach($this->fields as $field)
		{
			$fields[] = '`' . $field->table . '`.`' . $field->name . '` AS ' . $field->alias;
		}
		$fields = implode(',', $fields);
		
		//get data
		$db = Database::db()->connection;
		$query = 'SELECT SQL_CALC_FOUND_ROWS ' . $fields . ' FROM ' . $this->table . ' WHERE ' . $this->filter . ' LIMIT ' . $limit_start . ',' . $this->rows_per_page;
		$res = $db->query($query);
		if($res === false)
		{
			Log::error('Query failed for dataTable ' . $this->title
				. ' with error ' . $db->errno . ': ' . $db->error . ' -- ' . $query);
			return '';
		}
		$query = 'SELECT FOUND_ROWS()';
		$total_res = $db->query($query);
		if($total_res === false)
		{
			Log::error('Query failed for dataTable ' . $this->title
				. ' with error ' . $db->errno . ': ' . $db->error . ' -- ' . $query);
			return '';
		}
		$total = $total_res->fetch_array()[0];
		$total_res->close();
		$data = array();
		while($row = $res->fetch_assoc())
		{
			$data[] = $row;
		}
		$res->close();
		
		//calculate data
		$pages = ($this->rows_per_page < 1) ? 0 : ceil(((double)$total) / $this->rows_per_page);
		
		//generate html
		$out = '';
		
		//feedback on previous submit
		$this->outputMessage = array_filter($this->outputMessage);
		if(!empty($this->outputMessage))
		{
			$out .= '<ul>';
			foreach($this->outputMessage as $retmsg) $out .= '<li>' . $retmsg . '</li>';
			$out .= '</ul>';
		}
		
		//rows to display and/or edit
		$pageInput = '<input type="hidden" name="' . $this->inPrefix . 'page' . '" value="' . $this->page . '"/>';
		$out .= '<div style="text-align:center;display:inline-block;">';
		$out .= "\n" . '  <div class="ki_table">';
		if($this->rows_per_page > 0) //for add-only forms with no output, skip showing headers. The field names are in the placeholders anyway.
		{
			$out .= "\n" . '   <div>';
			//data column headers
			foreach($this->fields as $field)
			{
				if($field->show) $out .= "\n" . '    <div>' . $field->alias . '</div>';
			}
			//add/save/delete button column header
			if($this->allow_edit || $this->allow_add || ($this->allow_delete !== false)) $out .= "\n" . '    <div class="ki_table_action">&nbsp;</div>';
			//custom callback column header
			if(!empty($this->buttonCallbacks)) $out .= "\n" . '    <div class="ki_table_action">&nbsp;</div>';
			$out .= "\n" . '   </div>';
		}
		$json_data = array();
		foreach($data as $row)
		{
			$dataRow = '';
			//data columns
			foreach($row as $col => $value)
			{
				$realCol = $this->realColName($col);
				if($realCol === NULL)
				{
					Log::error('DataTable got unknown alias back from database: ' . $alias);
					return '';
				}
				if(!$this->fields[$realCol]->show) continue;
				$value = htmlspecialchars($value);
				$dataRow .= "\n    " . '<div>';
				$dataCell = '';
				$cellType = NULL;
				if($this->fields[$realCol]->edit)
				{
					$cellType = "edit";
					$inputName = $this->inputId($realCol, $row);
					$inputAttributes = array();
					if($this->fields[$realCol]->constraints['type'] == 'checkbox')
					{
						$dataCell .= "\n     " . '<input type="hidden" name="' . $inputName . '" id="H' . $inputName . '" value="0"/>';
						$inputAttributes[] = 'value="1"';
						if($value) $inputAttributes[] = 'checked="checked"';
						$json_data[$inputName] = $value ? 1 : 0;
					}else{
						$inputAttributes[] = 'value="' . $value . '" ';
						$json_data[$inputName] = $value;
					}
					$inputAttributes[] = 'name="' . $inputName . '" id="' . $inputName . '"';
					$inputAttributes[] = $this->stringifyConstraints($realCol);
					$inputAttributes[] = 'class="ki_table_input"';
					$dataCell .= "\n     " . '<input ' . implode(' ', $inputAttributes) . '/>';
				}else{
					$cellType = "show";
					$dataCell .= "\n     " . $value;
				}
				if($this->fields[$realCol]->outputFilter !== NULL)
				{
					$filterFunc = $this->fields[$realCol]->outputFilter;
					$dataCell = $filterFunc($dataCell, $cellType);
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
					$buttonName = $this->inputId('0', $row, 'submit');
					$dataRow .=  '<input type="submit" name="' . $buttonName . '" id="' . $buttonName . '" value="💾" class="ki_button_save" title="Save"/>';
				}
				if($this->allow_edit || ($this->allow_delete !== false))
					$dataRow .= '<span class="ki_noscript_spacer"> - </span>';
				if($this->allow_delete !== false)
				{
					$buttonName = $this->inputId('0', $row, 'delete');
					$dataRow .= '<div class="ki_button_confirm_container">';
					$dataRow .= '<button type="button" id="' . $buttonName . '" class="ki_button_del" title="Delete" style="">❌</button>';
					$dataRow .= '<div class="ki_button_confirm"><input type="submit" name="' . $buttonName . '" value="Confirm Delete" formnovalidate /></div>';
					$dataRow .= '</div>';
				}
				$dataRow .= "\n" . '    </div>';
			}
			//custom callback buttons
			if(!empty($this->buttonCallbacks))
			{
				$dataRow .= "\n" . '    <div class="ki_table_action">' . $pageInput;
				foreach($this->buttonCallbacks as $cbName => $cbFunc)
				{
					$buttonName = $this->inputId('0', $row, 'callback_'.$cbFunc);
					$dataRow .= '<input type="submit" name="' . $buttonName . '" id="' . $buttonName . '" class="ki_button_action" value="' . $cbName . '" formnovalidate />';
				}
				$dataRow .= "\n" . '    </div>';
			}
			$out .= "\n" . '   <form method="post" action="' . $_SERVER['SCRIPT_NAME'] . '">' . $dataRow . "\n" . '   </form>';
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
					$inputName = $this->inPrefix . 'new_' . htmlspecialchars($col);
					$inputAttributes = array();
					$inputAttributes[] = 'name="' . $inputName . '" id="' . $inputName . '"';
					$inputAttributes[] = $this->stringifyConstraints($col);
					if($val->constraints['type'] == 'checkbox')
					{
						$addingCell .= '<input type="hidden" name="' . $inputName . '" id="H' . $inputName . '" value="0"/>';
						$inputAttributes[] = 'value="1"';
						if($val->defaultValue) $inputAttributes[] = 'checked="checked"';
					}else{
						$inputAttributes[] = 'value="' . $val->defaultValue . '"';
						$inputAttributes[] = 'placeholder="' . $val->alias . '"';
					}
					$addingCell .= '<input ' . implode(' ', $inputAttributes) . '/>';
				}else{
					$addingCell .= $directive;
				}
				if($this->fields[$col]->outputFilter !== NULL)
				{
					$filterFunc = $this->fields[$col]->outputFilter;
					$addingCell = $filterFunc($addingCell, 'add');
				}
				$out .= $addingCell;
				$out .= "\n    " . '</div>';
			}
			$out .= "\n    " . '<div class="ki_table_action">' . "\n     " . $pageInput . "\n     " . '<input type="submit" title="Add" value="+" class="ki_button_add"/>' . "\n    " . '</div>';
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
			if(!self::$anyPrinted) $js .= <<<'HTML'
			$(".ki_button_save").css("position","absolute");
			$(".ki_noscript_spacer").remove();
			$("input").on("change keydown keyup blur", function(){
				ki_setEditVisibility($(this).parent().parent().find('.ki_button_save'));
			});
			
			function ki_setEditVisibility(btn)
			{
				var buttonFields = btn.parent().parent().find('.ki_table_input').not('.ws-inputreplace');
				var delBtn = btn.parent().parent().find('.ki_button_confirm_container');
				for(var i = 0; i < buttonFields.length; i++)
				{
					var control = $(buttonFields[i]);
					var changed = false;
					if(control.attr('type') == "checkbox")
					{
						changed = (control.prop('checked') == true) != (inputValues[control.attr('id')] == true);
					}else{
						changed = control.prop('value') != inputValues[control.attr('id')];
					}
					if(changed)
					{
						btn.css("z-index","30");
						return;
					}
				}
				btn.css("z-index","5");
			}
HTML;
			$js = str_replace('inputValues',$arrIV,$js);
			$js .= '</script>';
			$out .= $js;
		}
		self::$anyPrinted = true;
		return $out;
	}
	
	/**
	* Given a column name, returns an array of html5 validation constraints that apply to it
	* including the type attribute
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
	* Get constraints for column in HTML form ready to be inserted into an input
	*/
	protected function stringifyConstraints($col)
	{
		$out = '';
		foreach($this->fields[$col]->constraints as $cname => $cval)
		{
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
	function handleParams($post = NULL, $get = NULL)
	{
		Log::trace('Handling params for DataTable ' . $this->title);
		$db = Database::db()->connection;
		$didSomething = false;
		
		//check preconditions
		if(!$this->setupOK) return false;
		if($this->handledParams)
		{
			Log::error('Tried to handle params for the same DataTable twice in one page load');
			return false;
		}
		$this->handledParams = true;
		if($this->printed)
		{
			Log::error('DataTable handling params after generating HTML, but getHtml assumes params have already been handled and probably showed wrong information');
		}
		
		//interpret arguments
		if($post === NULL) $post = $_POST;
		if($get === NULL) $get = $_GET;

		//set state from params
		if(isset($get[$this->inPrefix . 'page']) && empty($post))
		{
			Log::trace('DataTable ' . $this->title . ' found GET and no POST');
			$this->page = (int)$get[$this->inPrefix . 'page'];
			return false; //if GET was used, don't process POST
		}
		Log::trace('DataTable ' . $this->title . ' done checking for GET, continuing to POST');
		if(isset($post[$this->inPrefix . 'page'])) $this->page = (int)$post[$this->inPrefix . 'page'];
		
		//interpret and verify edits to save
		$editPrefix = $this->inPrefix . 'edit_';
		$changesToSave = array();
		$newPrefix = $this->inPrefix . 'new_';
		$newRow = array();
		$deletePrefix = $this->inPrefix . 'delete_';
		$deleteKeys = array();
		$callbackPrefix = $this->inPrefix . 'callback_';
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
				$key = json_decode($key);
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
				$deleteKeys[] = $pk_values;
			}
			elseif(mb_strpos($key,$callbackPrefix) === 0) //check for custom callback button
			{
				$cbName = $value;
				$key = mb_substr($key,mb_strlen($callbackPrefix));
				$cbFunc = mb_substr($key,0,mb_strpos($key,'_'));
				$key = mb_substr($key,mb_strlen($cbFunc)+1);
				
				$key = base64_decode($key);
				if($key === false)
				{
					Log::warn('DataTable callback button failed base64 decoding');
					continue;
				}
				$key = json_decode($key);
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
				if(!isset($this->buttonCallbacks[$cbName]) || $this->buttonCallbacks[$cbName] != $cbFunc)
				{
					Log::warn('DataTable callback button specificed invalid function/name: ' . $cbName . ',' . $cbFunc);
					continue;
				}
				$pkNamed = array();
				foreach($this->pk as $index => $pname) $pkNamed[$pname] = $pk_values[$index];
				$cbFunc($pkNamed);
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
				$key = json_decode($key);
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
				$col = mb_substr($key,mb_strlen($newPrefix));
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
				$query = 'DELETE FROM ' . $this->table . ' WHERE ';
			}else{
				$query = 'UPDATE ' . $this->table . ' SET ' . $this->allow_delete . '=0 WHERE ';
			}
			$pkNamed = array();
			$conditions = array();
			foreach($pk as $index => $value)
			{
				$col = $this->pk[$index];
				$pkNamed[$col] = $value;
				if(mb_strpos($this->fields[$col]->dataType,'int') !== false)
				{
					$value = (int)$value;
				}else{
					$value = '"' . $db->real_escape_string($value) . '"';
				}
				$conditions[] = '`' . $db->real_escape_string($col) . '`=' . $value;
			}
			$conditions[] = $this->filter; //this line only allows deleting rows which match the filter
			$query .= implode(' AND ', $conditions) . ' LIMIT 1;';
			
			if(isset($this->eventCallbacks->beforeDelete))
			{
				$cbFunc = $this->eventCallbacks->beforeDelete;
				$cbRes = $cbFunc($pkNamed);
				if($cbRes !== true)
				{
					$this->outputMessage[] = $cbRes;
					continue;
				}
			}
			
			$res = $db->query($query);
			if($res === false)
			{
				Log::warn('DataTable ' . $this->title . ': Bad query deleting row: ' . $query);
				$this->outputMessage[] = 'Failed to delete row ' . htmlspecialchars(implode(',',$pk));
			}else{
				if($db->affected_rows == 0)
				{
					$this->outputMessage[] = 'Could not find row ' . htmlspecialchars(implode(',',$pk));
				}else{
					$this->outputMessage[] = 'Successfully ' . (($this->allow_delete === true) ? 'deleted' : 'disabled') . ' row ' . htmlspecialchars(implode(',',$pk));
					$didSomething = true;
					if(isset($this->eventCallbacks->onDelete))
					{
						$cbFunc = $this->eventCallbacks->onDelete;
						$msg_onDelete = $cbFunc($pkNamed);
						if(!empty($msg_onDelete)) $this->outputMessage[] = $msg_onDelete;
					}
				}
			}
		}
		
		//query for saving edits
		foreach($changesToSave as $pk => $vals) //for each row
		{
			Log::trace('Checking changes for row ' . $pk);
			$pk = json_decode($pk);
			$query = 'UPDATE `' . $this->table . '` SET ';
			$setVals = array();

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
				if($value == "")
				{
					$value = 'NULL';
				}else{
					if(mb_strpos($this->fields[$col]->dataType,'int') !== false)
					{
						$value = (int)$value;
					}else{
						$value = '"' . $db->real_escape_string($value) . '"';
					}
				}
				$setVals[] = '`' . $db->real_escape_string($col) . '`=' . $value;
			}
			
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

			
			$query .= implode(',', $setVals) . ' WHERE ';
			$pkNamed = array();
			$conditions = array();
			foreach($pk as $index => $value)
			{
				$col = $this->pk[$index];
				$pkNamed[$col] = $value;
				if(mb_strpos($this->fields[$col]->dataType,'int') !== false)
				{
					$value = (int)$value;
				}else{
					$value = '"' . $db->real_escape_string($value) . '"';
				}
				$conditions[] = '`' . $db->real_escape_string($col) . '`=' . $value;
			}
			$conditions[] = $this->filter; //this line only allows editing rows which match the filter
			$query .= implode(' AND ', $conditions) . ' LIMIT 1;';
			$res = $db->query($query);
			if($res === false)
			{
				Log::warn('DataTable ' . $this->title . ': Bad query updating row: ' . $query);
				$this->outputMessage[] = 'Failed to update row ' . htmlspecialchars(implode(',',$pk));
			}else{
				if($db->affected_rows == 0)
				{
					$this->outputMessage[] = 'Could not find editable row for ' . htmlspecialchars(implode(',',$pk)) . ' or nothing was edited.';
				}else{
					$this->outputMessage[] = 'Successfully updated row ' . htmlspecialchars(implode(',',$pk));
					$didSomething = true;
					if(isset($this->eventCallbacks->onEdit))
					{
						$cbFunc = $this->eventCallbacks->onEdit;
						$msg_onEdit = $cbFunc($pkNamed);
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
				foreach($newRow as $col => $value)
				{
					if(in_array($col,$this->pk)) $pk[$col] = $value;
					$setter = '`' . $col . '`=';
					if($value == "")
					{
						$setter .= 'NULL';
					}else{
						if(mb_strpos($this->fields[$col]->dataType,'int') !== false)
						{
							$setter .= (int)$value;
						}else{
							$setter .= '"' . $db->real_escape_string($value) . '"';
						}
					}
					$setters[] = $setter;
				}
				$query .= implode(',', $setters);
				$res = $db->query($query);
				if($res === false)
				{
					$err = $db->error;
					$this->outputMessage[] = 'Error adding new row: ' . $err;
					Log::error('Bad SQL query adding new row: '  . $err . ' for query: ' . $query);
				}else{
					$insert_id = $db->insert_id;
					$message = 'New row added successfully';
					if($insert_id != 0)
					{
						$message .= ' with number ' . $insert_id;
						$pk[$this->autoCol] = $insert_id;
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
	*/
	protected function pager($pages)
	{
		$out = '<div style="text-align:center;display:inline-block;">';
		$pageList = Util::pagesToShow($this->page,$pages);
		$pageParam = $this->inPrefix . 'page';
		
		//first row: arrows and direct page input
		$out .= '<span style="float:left;">';
		if($this->page > 1)
		{
			$out .= '<a href="?' . $pageParam . '=1">⇤</a> &nbsp; ';
			$out .= '<a href="?' . $pageParam . '=' . ($this->page - 1) . '">⬅</a> &nbsp; ';
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
			. '</form> &nbsp; ';
		
		$out .= '<span style="float:right;">';
		if($this->page < $pages)
		{
			$out .= '<a href="?' . $pageParam . '=' . ($this->page + 1) . '">➡</a> &nbsp; ';
			$out .= '<a href="?' . $pageParam . '=' . $pages . '">⇥</a>';
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
				$out .= '<a href="?' . $pageParam . '=' . $pnum . '">' . $pnum . '</a>';
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
	*/
	protected function inputId($colname, $row, $type='edit')
	{
		$out = array();
		foreach($row as $col => $value)
		{
			$col = $this->realColName($col);
			
			if(in_array($col, $this->pk))
			{
				$out[] = $value;
			}
		}
		return $this->inPrefix . $type . '_' . str_replace('=','',base64_encode(json_encode(array($colname,$out))));
	}
	
	/**
	* find out the real name of the column if what you have is an alias
	*/
	protected function realColName($col)
	{
		foreach($this->fields as $field)
		{
			if($field->alias == $col) return $field->name;
		}
		return $col;
	}
	
	/**
	* Translate mysql type to html input type
	*/
	protected function formType($type)
	{
		if($type == 'tinyint(1)') return 'checkbox';
		if(mb_strpos($type, 'int') !== false) return 'number';
		if(mb_strpos($type, 'date') !== false) return 'date';
		if(mb_strpos($type, 'datetime') !== false) return 'datetime';
		
		return 'text';
	}
}

?>