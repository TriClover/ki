<?php
namespace mls\ki\Widgets;
use \mls\ki\Database;
use \mls\ki\Log;
use \mls\ki\Util;

class DataTableField
{
	//Identification
	public $name;
	public $table;
	public $alias = NULL;
	public $manyToMany = false;
	
	//Where to use the field
	public $show = true;
	public $edit = false;
	public $add  = true;

	//Validation
	public $constraints = array();

	//Presentation
	public $outputFilter = NULL;

	//Schema
	public $dataType     = NULL;
	public $nullable     = NULL;
	public $keyType      = NULL; //PRI, UNI, MUL
	public $defaultValue = NULL;
	public $extra        = NULL;
	public $fkReferencedTable = NULL;
	public $fkReferencedField = NULL;

	//Metadata
	public $serialNum = NULL; //index with which this field was originally provided to the DataTable, for compact referencing
	public $numOptions = NULL;
	public $dropdownLimit = NULL;
	public $dropdownOptions = [];
	
	//State
	public $filled = false;

	/**
	* Constructing a DataTableField fills it with the data used for identifying the field
	* and the options for this field that come from the DataTable.
	* It does not fill the schema related information.
	* @param name          The column name in the schema (as string).
	*                       If NULL, use these settings for fields not specified.
	* @param table         The table name in the schema where this field resides.
	*                       Must be a table specified in the DataTable unless the manyToMany parameter is true (see below)
	* @param alias         Used for display, and as the alias in any queries. NULL = $table.$name
	* @param show          Whether a DataTable will show this column
	*                       true  = show
	*                       false = don't show
	*                       NULL  = don't show by default but make available for showing in the query builder
	* @param edit          Whether a DataTable will allow editing this column
	* @param add           What to do for this field when adding new rows.
	*                       true=allow editing
	*                       false=disallow and use default/auto value
	*                       string/number/NULL=disallow and use this value instead
	* @param constraints   HTML5 form validation constraints. These will be used directly in the form and interpreted for server-side checks.
	* @param outputFilter  Function that recieves table cell contents and outputs what they will be replaced with. Second parameter is the cell type: (show, edit, add)
	* @param dropdownLimit If this field is eligible to become a FK based dropdown, calculate the number of options it would have, and if it is more than dropdownLimit then revert to making it a text field instead to avoid excessive page load time
	* @param manyToMany    If true, this field will be a virtual field representing a
	*                       many-to-many relation between the DataTable's main table and some
	*                       other table, in the form of a multi-select control.
	*                       Both the DataTable's main table and the related table must have single field primary keys.
	*                       There must also be a relational table having foreign keys to the PKs of the two aforementioned tables. It will be found automatically.
	*                       Other parameters will be interpereted as follows:
	*                       table = the related table with which to make and break associations
	*                       name = the field in the related table to show as values in the select.
	*/
	function __construct(         $name,
	                     string   $table,
						          $alias = NULL,
						          $show = true,
						 bool     $edit = false,
						          $add = NULL,
						 array    $constraints = array(),
						 callable $outputFilter = NULL,
						 int      $dropdownLimit = 200,
						 bool     $manyToMany = false)
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
		$this->dropdownLimit = $dropdownLimit;
		
		if($manyToMany)
		{
			$this->manyToMany = new DataTableMultiRelation($table, $alias, $name);
		}
	}
	
	function fqName(bool $quoted = false)
	{
		$q = $quoted ? '`' : '';
		return $q . $this->table . $q . '.' . $q . $this->name . $q;
	}
	
	/**
	* Apply found schema info for this field, and apply usage restrictions based on the schema.
	* @param dt the DataTable using this field
	* @param row the row from SHOW COLUMNS applying to this field
	*/
	function fillSchemaInfo(\mls\ki\Widgets\DataTable &$dt, array $row)
	{
		//Don't allow editing on fields critical to a join. It can work, but with a result that would be very confusing to the user.
		foreach($dt->joinTables as $join)
		{
			if(($this->table == $join->mainTable && $row['Field'] == $join->mainTableFKField)
				|| ($this->table == $join->joinTable && $row['Field'] == $join->joinTableReferencedUniqueField))
			{
				$this->edit = false;
			}
		}
		
		//Don't accept the default of allowing user value for add on auto_increment column
		if(mb_strpos($row['Extra'],'auto_increment') !== false)
		{
			$this->add = false;
		}

		//store schema info in the field config
		$this->dataType     = $row['Type'];
		$this->nullable     = $row['Null'];
		$this->keyType      = $row['Key'];
		$this->defaultValue = $row['Default'];
		$this->extra        = $row['Extra'];
		
		if(Util::startsWith($this->dataType, 'enum'))
		{
			$ops = mb_substr($this->dataType, 6, mb_strlen($this->dataType)-8);
			$this->dropdownOptions = explode("','", $ops);
		}
		
		if($this->manyToMany)
		{
			$this->manyToMany->fillKeys($dt);
		}
		
		$this->filled = true;
	}
	
	static function fillSchemaInfoAll(\mls\ki\Widgets\DataTable &$dt)
	{
		$db = Database::db();
		$table = [$dt->table];
		foreach($dt->joinTables as $t) $table[] = $t->joinTable;
		
		//get schema info for all the tables involved and fill it into the field objects
		//creating field objects with default setup for fields not specified
		$fk = [];
		$fieldSerial = 1;
		foreach($table as $tab)
		{
			$query = 'SHOW COLUMNS FROM `' . $tab . '`';
			$res = $db->query($query, [], 'getting schema info for dataTable ' . $dt->title);
			if($res === false)
			{
				return false;
			}
			foreach($res as $row)
			{
				$fieldFQ = $tab . '.' . $row['Field'];
				//if this field isn't in the config list, add it with default settings
				if(!isset($dt->fields[$fieldFQ]))
				{
					Log::trace('DataTable ' . $dt->title . ' applying default values for unconfigured field: ' . $fieldFQ);
					$dt->fields[$fieldFQ] = clone $dt->defaultField;
					$dt->fields[$fieldFQ]->name = $row['Field'];
					$dt->fields[$fieldFQ]->alias = $fieldFQ;
					$dt->fields[$fieldFQ]->table = $tab;
					//Exception: Never try to enable editing on PK fields
					if($row['Key'] == 'PRI') $dt->fields[$fieldFQ]->edit = false;
				}
				
				$dt->fields[$fieldFQ]->fillSchemaInfo($dt, $row);
				
				//keep track of which fields have certain properties so we're not searching later
				if($tab == $dt->table)
				{
					if($row['Key'] == 'PRI')
						$dt->pk[] = $row['Field'];
					elseif($row['Key'] == 'MUL')
						$fk[] = $row['Field'];
					if(mb_strpos($row['Extra'],'auto_increment') !== false)
						$dt->autoCol = $row['Field'];
				}else{
					if($row['Key'] == 'PRI')
						$dt->joinTables[$tab]->pk[] = $row['Field'];
					if(mb_strpos($row['Extra'],'auto_increment') !== false)
						$dt->joinTables[$tab]->autoCol = $row['Field'];
				}
				//bail on duplicate alias
				if(isset($dt->alias2fq[$dt->fields[$fieldFQ]->alias]))
				{
					Log::error('DataTable ' . $dt->title . ' specified a duplicate alias.');
					return false;
				}else{
					$dt->alias2fq[$dt->fields[$fieldFQ]->alias] = $fieldFQ;
				}
				
				//give the field its serial number
				$dt->fields[$fieldFQ]->serialNum = ++$fieldSerial;
			}
		}
		
		//For all the fields that are potentially foreign keys, check if they are
		$colParam = '';
		foreach($fk as $index => $f)
		{
			if($index > 0) $colParam .= ',';
			$colParam .= '"' . $db->esc($f) . '"';
		}
		if(!empty($colParam))
		{
			$query = <<<SQL
SELECT `keys`.`COLUMN_NAME` AS 'field',
	`keys`.`REFERENCED_TABLE_NAME` AS 'refTable',
	`keys`.`REFERENCED_COLUMN_NAME` AS 'refColumn'
FROM
	(
		SELECT * FROM `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`
        WHERE `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`.`CONSTRAINT_SCHEMA` = ?         /*param dbname*/
			AND `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`.`TABLE_SCHEMA` = ?            /*param dbname*/
            AND `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`.`TABLE_NAME` = ?              /*param table */
            AND `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`.`COLUMN_NAME` IN($colParam)   /*fake param because stupid API can't bind arrays */
			AND `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`.`REFERENCED_TABLE_SCHEMA` = ? /*param dbname*/
	) AS `keys`
	INNER JOIN
    (
		SELECT * FROM `information_schema`.`TABLE_CONSTRAINTS`
		WHERE `information_schema`.`TABLE_CONSTRAINTS`.`CONSTRAINT_TYPE` = 'FOREIGN KEY' 
			AND `information_schema`.`TABLE_CONSTRAINTS`.`TABLE_SCHEMA` = ?      /*param dbname*/
            AND `information_schema`.`TABLE_CONSTRAINTS`.`CONSTRAINT_SCHEMA` = ? /*param dbname*/
			AND `information_schema`.`TABLE_CONSTRAINTS`.`TABLE_NAME` = ?        /*param table*/
	) AS `constraints`
    ON `keys`.`CONSTRAINT_NAME` = `constraints`.`CONSTRAINT_NAME`
SQL;
			$dbname = $db->dbName;
			$params = [$dbname, $dbname, $dt->table, $dbname, $dbname, $dbname, $dt->table];
			$fkRes = $db->query($query, $params, 'getting foreign keys for DataTable');
			//for foreign key fields, fill the relevant info
			foreach($fkRes as $row)
			{
				$fieldFQ = $dt->table . '.' . $row['field'];
				$dt->fields[$fieldFQ]->fkReferencedTable = $row['refTable'];
				$dt->fields[$fieldFQ]->fkReferencedField = $row['refColumn'];
				
				$countQuery = 'SELECT COUNT(DISTINCT ' . $db->esc($dt->fields[$fieldFQ]->fkReferencedField)
					. ') AS n FROM ' . $db->esc($dt->fields[$fieldFQ]->fkReferencedTable);
				$countRes = $db->query($countQuery, [], 'Getting number of options for FK field');
				$dt->fields[$fieldFQ]->numOptions = $countRes[0]['n'];
				//gather data for making the FK field a dropdown if eligible
				if($dt->fields[$fieldFQ]->numOptions <= $dt->fields[$fieldFQ]->dropdownLimit)
				{
					$opsQuery = 'SELECT DISTINCT ' . $db->esc($dt->fields[$fieldFQ]->fkReferencedField)
						. ' FROM ' . $db->esc($dt->fields[$fieldFQ]->fkReferencedTable);
					$opsRows = $db->query($opsQuery, [], 'getting values for FK dropdown');
					foreach($opsRows as $opsRow)
						$dt->fields[$fieldFQ]->dropdownOptions[] = $opsRow[$dt->fields[$fieldFQ]->fkReferencedField];
				}
			}
		}

		//Fields that didn't get schema info filled above must be virtual
		foreach($dt->fields as $fieldFQ => $field)
		{
			if(!$field->filled)
			{
				$dt->fields[$fieldFQ]->serialNum = ++$fieldSerial;
				//take action only for each valid kind of virtual field
				//don't process fields that are missing just because they weren't in the table
				if($field->manyToMany !== false)
				{
					$row = ['Field' => $field->name, 'Type' => 'virtual', 'Null' => 'YES', 'Key' => '', 'Default' => NULL, 'Extra' => ''];
					$dt->fields[$fieldFQ]->fillSchemaInfo($dt, $row);
					$dt->alias2fq[$dt->fields[$fieldFQ]->alias] = $fieldFQ;
				}
			}
		}
		
		return true;
	}
}
?>