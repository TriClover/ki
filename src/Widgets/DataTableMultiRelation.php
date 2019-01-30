<?php
namespace mls\ki\Widgets;
use \mls\ki\Database;
use \mls\ki\Log;


/**
* Defines a many-to-many relation between the main DB table of a DataTable and another DB table
* to be displayed in the DataTable as a <select multiple> column.
* Used internally by DataTableField
*/
class DataTableMultiRelation
{
	//Establish the relation
	public $mainTable;
	public $mainTablePKField;
	public $relatedTable;
	public $relatedTablePKField;
	public $relationTable;
	public $relationTableMainFKField;
	public $relationTableRelatedFKField;
	
	//Control display
	public $alias;
	public $relatedTableDisplayField;
	
	//Metadata
	public $dropdownOptions = [];
	
	/**
	* Gives a SQL field identifier in the form `tablename`.`columnname`
	* @param table the DB table name
	* @param field the DB field/column name
	* @param quoted whether to include the backtick quoting
	*/
	public function fieldFQ(string $table, string $field, bool $quoted = true)
	{
		$q = $quoted ? '`' : '';
		return $q . $table . $q . '.' . $q . $field . $q;
	}
	
	public function relatedTableDisplayFieldFQ(bool $quoted = true)
	{
		return $this->fieldFQ($this->relatedTable, $this->relatedTableDisplayField, $quoted);
	}
	
	public function mainTablePKFieldFQ(bool $quoted = true)
	{
		return $this->fieldFQ($this->mainTable, $this->mainTablePKField, $quoted);
	}
	
	public function relatedTablePKFieldFQ(bool $quoted = true)
	{
		return $this->fieldFQ($this->relatedTable, $this->relatedTablePKField, $quoted);
	}
	
	public function relationTableMainFKFieldFQ(bool $quoted = true)
	{
		return $this->fieldFQ($this->relationTable, $this->relationTableMainFKField, $quoted);
	}
	
	public function relationTableRelatedFKFieldFQ(bool $quoted = true)
	{
		return $this->fieldFQ($this->relationTable, $this->relationTableRelatedFKField, $quoted);
	}
	
	/**
	* @param relatedTable the table from which we are associating values with the main table
	* @param alias the column name displayed in the DataTable. If NULL use the table name.
	* @param relatedTableDisplayField The field from which to get the values shown in the <select>. If NULL use the primary key. If array, show multiple.
	*/
	function __construct(string $relatedTable,
	                     string $alias = NULL,
	                            $relatedTableDisplayField = NULL)
	{
		$this->relatedTable = $relatedTable;
		$this->alias = $alias === NULL ? $relatedTable : $alias;
		$this->relatedTableDisplayField = $relatedTableDisplayField;
	}
	
	/**
	* Gather key related information
	* @param dt the DataTable within which this object will be used
	* @return true on success, false if the setup was found to be invalid or on any DB error
	*/
	public function fillKeys(\mls\ki\Widgets\DataTable &$dt)
	{
		if(count($dt->pk) != 1)
		{
			Log::error('To use DataTableMultiRelation the main table must have a single column primary key');
			return false;
		}
		if($this->relatedTableDisplayField === NULL) $this->relatedTableDisplayField = $dt->pk[0];
		$this->mainTable = $dt->table;
		$this->mainTablePKField = $dt->pk[0];
		
		$db = Database::db();
		$query = 'SHOW COLUMNS FROM `' . $db->esc($this->relatedTable) . '` WHERE `Key`="PRI"';
		$relatedPK = $db->query($query, [], 'getting PK of related table');
		if(count($relatedPK) != 1)
		{
			Log::error('To use DataTableMultiRelation the related table must have a single column primary key');
			return false;
		}
		$this->relatedTablePKField = $relatedPK[0]['Field'];
		
		$relationQuery = $this->findRelationQuery;
		$relationParams = [$db->dbName, $db->dbName, $db->dbName,
			$this->mainTable, $this->mainTablePKField, $this->mainTable, $this->relatedTable,
			$this->relatedTable, $this->relatedTablePKField, $this->mainTable, $this->relatedTable];
		$relationParams = array_merge($relationParams, $relationParams);
		$relationRes = $db->query($relationQuery, $relationParams, 'finding relation table');
		if(count($relationRes) != 2)
		{
			Log::error('DataTableMultiRelation could not find a relation table (or found more than one) between ' . $this->mainTable . '.' . $this->mainTablePKField . ' and ' . $this->relatedTable . '.' . $this->relatedTablePKField);
			return false;
		}
		$this->relationTable = $relationRes[0]['relation_table'];
		if($relationRes[0]['refTable'] == $this->mainTable && $relationRes[0]['refColumn'] == $this->mainTablePKField
			&& $relationRes[1]['refTable'] == $this->relatedTable && $relationRes[1]['refColumn'] == $this->relatedTablePKField)
		{
			$this->relationTableMainFKField = $relationRes[0]['relation_column'];
			$this->relationTableRelatedFKField = $relationRes[1]['relation_column'];
		}
		elseif($relationRes[1]['refTable'] == $this->mainTable && $relationRes[1]['refColumn'] == $this->mainTablePKField
			&& $relationRes[0]['refTable'] == $this->relatedTable && $relationRes[0]['refColumn'] == $this->relatedTablePKField)
		{
			$this->relationTableMainFKField = $relationRes[1]['relation_column'];
			$this->relationTableRelatedFKField = $relationRes[0]['relation_column'];
		}else{
			Log::error('DataTableMultiRelation could not find a relation table with the right key relationship between ' . $this->mainTable . '.' . $this->mainTablePKField . ' and ' . $this->relatedTable . '.' . $this->relatedTablePKField);
			return false;
		}
		
		//fill dropdown options
		$ddQuery = 'SELECT ' . $this->relatedTablePKFieldFQ() . ' AS "' . $this->relatedTablePKFieldFQ(false) . '"'
			. ($this->relatedTableDisplayField != $this->relatedTablePKField ? (',' . $this->relatedTableDisplayFieldFQ() . ' AS "' . $this->relatedTableDisplayFieldFQ(false) . '"') : '')
			. ' FROM `' . $this->relatedTable . '`';
		$ddRes = $db->query($ddQuery, [], 'Getting possible value list for many-to-many relation');
		foreach($ddRes as $ddRow)
		{
			$relatedTablePKVal      = $ddRow[$this->relatedTablePKFieldFQ(false)];
			$relatedTableDisplayVal = $ddRow[$this->relatedTableDisplayFieldFQ(false)];
			$pkAndDisplayAreSameField = $this->relatedTableDisplayField == $this->relatedTablePKField;
			$dispString = $relatedTablePKVal;
			if(!$pkAndDisplayAreSameField) $dispString .= ': ' . $relatedTableDisplayVal;
			$this->dropdownOptions[$relatedTablePKVal] = $dispString;
		}
		return true;
	}
	
	public function optionsHTML($valKeys)
	{
		$out = '';
		foreach($this->dropdownOptions as $val => $disp)
		{
			$out .= '<option value="' . htmlspecialchars($val) . '"'
				. (in_array($val, $valKeys) ? ' selected' : '') . '>'
				. htmlspecialchars($disp) . '</option>';
		}
		
		return $out;
	}
	
	/**
	* Updates the data in the relation table so that the given main table PK value is
	* associated with only the given set of related table PK values
	* @param mainTablePKValue The main table PK value for which to manage associations
	* @param relatedTablePKValues the set of related table PK values to be acciated with the given main table PK value
	* @return true on success, string on failure
	*/
	public function updateData($mainTablePKValue, $relatedTablePKValues)
	{
		$pk = $mainTablePKValue;
		$value = $relatedTablePKValues;
		
		$db = Database::db();
		$out = [];
		
		$mainTable                  ='`'.$this->mainTable                  .'`';
		$mainTablePKField           ='`'.$this->mainTablePKField           .'`';
		$relatedTable               ='`'.$this->relatedTable               .'`';
		$relatedTablePKField        ='`'.$this->relatedTablePKField        .'`';
		$relatedTableDisplayField   ='`'.$this->relatedTableDisplayField   .'`';
		$relationTable              ='`'.$this->relationTable              .'`';
		$relationTableRelatedFKField='`'.$this->relationTableRelatedFKField.'`';
		$relationTableMainFKField   ='`'.$this->relationTableMainFKField   .'`';
		
		$firstPkVal = array_values($pk)[0];
		
		if(!empty($value))
		{
			$literalTable = [];
			foreach($value as $fkVal)
			{
				$literalTable[] = '(SELECT ' . $db->esc($firstPkVal) . " AS '" . $this->relationTableMainFKField
					. "', " . $db->esc($fkVal) . " AS '" . $this->relationTableRelatedFKField . "')";
			}
			$literalTable = '(' . implode(' UNION ', $literalTable) . ') AS `literal`';
			
			$queryAdd = <<<QUERYADD_END
INSERT INTO $relationTable ($relationTableMainFKField,$relationTableRelatedFKField)
SELECT $relationTableMainFKField,$relationTableRelatedFKField
FROM
	$literalTable
WHERE
	($relationTableMainFKField,$relationTableRelatedFKField)
	NOT IN
	(SELECT $relationTableMainFKField,$relationTableRelatedFKField FROM $relationTable)
QUERYADD_END;
			$res = $db->query($queryAdd, [], 'adding relation values');
			if($res === false)
			{
				$out[] = 'Failed to add associations to ' . $relationTable;
			}
			
			$currentVals = [];
			foreach($value as $fkVal)
			{
				$currentVals[] = $db->esc($fkVal);
			}
			$currentVals = implode(',',$currentVals);
			$queryDel = <<<QUERYDEL_END
DELETE FROM $relationTable
WHERE
	$relationTableMainFKField=?
	AND
	$relationTableRelatedFKField NOT IN($currentVals)
QUERYDEL_END;
			$res = $db->query($queryDel, [$firstPkVal], 'deleting relation values');
			if($res === false)
			{
				$out[] = 'Failed to prune associations to ' . $relationTable;
			}
		}else{
			$res = $db->query("DELETE FROM $relationTable WHERE $relationTableMainFKField=?", [$firstPkVal], 'deleting relation values');
			if($res === false)
			{
				$out[] = 'Failed to prune associations to ' . $relationTable;
			}
		}
		return ($out == []) ? true : implode(',', $out);
	}
	
	const findRelationDerivedTable = <<<'END_SQL'
SELECT *
FROM `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`
WHERE
	`CONSTRAINT_NAME` IN(SELECT `CONSTRAINT_NAME` FROM `information_schema`.`TABLE_CONSTRAINTS` WHERE `CONSTRAINT_TYPE`='FOREIGN KEY')
	AND `CONSTRAINT_SCHEMA`=?                /* param: dbname */
	AND `TABLE_SCHEMA`=?                     /* param: dbname */
	AND `REFERENCED_TABLE_SCHEMA`=?          /* param: dbname */
	AND
	(
		(
			`REFERENCED_TABLE_NAME`=?        /* param: mainTable */
			AND `REFERENCED_COLUMN_NAME`=?   /* param: mainTablePK */
			AND `TABLE_NAME` NOT IN(?,?)     /* param: mainTable, relatedTable */
		)
		OR
		(
			`REFERENCED_TABLE_NAME`=?        /* param: relatedTable */
			AND `REFERENCED_COLUMN_NAME`=?   /* param: relatedTablePK */
			AND `TABLE_NAME` NOT IN(?,?)     /* param: mainTable, relatedTable */
		)
	)		
END_SQL;

	public $findRelationQuery = "
SELECT `a`.`TABLE_NAME` AS 'relation_table',
       `a`.`COLUMN_NAME` AS 'relation_column',
       `a`.`REFERENCED_TABLE_NAME` AS 'refTable',
       `a`.`REFERENCED_COLUMN_NAME` AS 'refColumn'
FROM (" . DataTableMultiRelation::findRelationDerivedTable . ") AS a INNER JOIN (" . DataTableMultiRelation::findRelationDerivedTable . ") AS b
ON `a`.`TABLE_NAME` = `b`.`TABLE_NAME` AND `a`.`REFERENCED_TABLE_NAME` != `b`.`REFERENCED_TABLE_NAME`
";

}

?>