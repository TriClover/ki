<?php
namespace mls\ki\Widgets;
use \mls\ki\Database;
use \mls\ki\Log;

/**
* Validates and keeps track of the information necessary for multi-table support in DataTable.
* Used internally by DataTable
*/
class DataTableJoin
{
	//setup
	public $mainTable;
	public $mainTableFKField;
	public $joinTable;
	public $joinTableReferencedUniqueField;
	public $joinTableAlias;
	
	//schema info assigned later by DataTable
	public $pk = array();   //fields that are in the primary key
	public $autoCol = NULL; //field with auto_increment, NULL if none
	
	/**
	* Instantiate the class; only meant to be used by DataTableJoin::create
	* @param $mainTable the main DB table being used in a DataTable
	* @param $mainTableFKField the field in the main table having the foreign key which is the basis of this join
	* @param $joinTable the DB table we want to join in
	* @param $joinTableReferencedUniqueField The field in the "join table" being referenced by the aformentioned foreign key. The field must be unique either by being a singleton primary key or having a Unique index.
	*/
	function __construct(string $mainTable,
	                     string $mainTableFKField,
	                     string $joinTable,
	                     string $joinTableReferencedUniqueField,
						 string $joinTableAlias = '')
	{
		$this->mainTable                      = $mainTable;
		$this->mainTableFKField               = $mainTableFKField;
		$this->joinTable                      = $joinTable;
		$this->joinTableReferencedUniqueField = $joinTableReferencedUniqueField;
		$this->joinTableAlias                 = ($joinTableAlias == '') ? $joinTable : $joinTableAlias;
	}
	
	/**
	* Validate whether the two tables are connected by the proper type of foreign key relationship
	* (main table foreign key to join table unique column)
	* and if so, return a DataTableJoin object representing the relation.
	* @param mainTable the primary table of the DataTable
	* @param joinTable a table to be joined in
	* @return a DataTableJoin object with the info needed to perform the join, or false if the info could not be found.
	*/
	static function create(string $mainTable, $joinTable, Database $db)
	{
		if($joinTable instanceof DataTableJoin) return [$joinTable];
		
		$query = <<<'QUERY'
SELECT 
	`COLUMN_NAME`,                            /* FK field name in main table */
	`REFERENCED_COLUMN_NAME`                  /* primary key of joined table */
FROM
	`INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE`
WHERE
	`REFERENCED_TABLE_SCHEMA` = ? AND         /* param: database */
	`REFERENCED_TABLE_NAME`   = ? AND         /* param: joined table */
	`TABLE_NAME`              = ? AND         /* param: main table */
	`REFERENCED_COLUMN_NAME` IN(              /* joined table column has some unique key */
		SELECT `COLUMN_NAME`
		FROM `INFORMATION_SCHEMA`.`COLUMNS`
		WHERE
			`TABLE_SCHEMA`   = ?              /* param: database */
			AND `TABLE_NAME` = ?              /* param: joined table */
			AND (
				`COLUMN_KEY` = 'UNI'          /* An actual 'UNI' key counts as 'some unique key' */
				OR (
					`COLUMN_KEY` = 'PRI'      /* PRI counts, except if the table has a compound primary key */
					AND 1 = (
						SELECT COUNT(`COLUMN_NAME`)
						FROM `INFORMATION_SCHEMA`.`COLUMNS`
						WHERE
							`TABLE_SCHEMA`   = ?          /* param: database */
							AND `TABLE_NAME` = ?          /* param: joined table */
							AND `COLUMN_KEY` = 'PRI'
					)
				)
			)
		)
QUERY;
		$params = [$db->dbName, $joinTable, $mainTable, $db->dbName, $joinTable, $db->dbName, $joinTable];
		$purpose = 'finding fields for ON clause of "' . $mainTable . ' LEFT JOIN ' . $joinTable . '"';
		$res = $db->query($query, $params, $purpose);
		if(empty($res)) return false;
		$out = [];
		if(count($res) == 1)
		{
			$row = $res[0];
			$out[] = new DataTableJoin($mainTable, $row['COLUMN_NAME'], $joinTable, $row['REFERENCED_COLUMN_NAME']);
		}else{
			foreach($res as $index => $row)
			{
				$alias = $joinTable . '_' . $row['COLUMN_NAME'];
				$out[] = new DataTableJoin($mainTable, $row['COLUMN_NAME'], $joinTable, $row['REFERENCED_COLUMN_NAME'], $alias);
			}
		}
		return $out;
	}
	
	/**
	* Like DataTableJoin::create, but for multiple join-tables. Invalid ones are excluded with logged error.
	* @param mainTable the primary table of the DataTable
	* @param joinTables all tables to be joined in
	* @return array of DataTableJoin objects
	*/
	static function createAll(string $mainTable, array $joinTables, Database $db)
	{
		$ret = [];
		foreach($joinTables as $jt)
		{
			$join = DataTableJoin::create($mainTable, $jt, $db);
			if($join === false)
			{
				Log::error('No DataTable-usable key arrangement found for ' . $mainTable . ' LEFT JOIN ' . $jt);
			}else{
				$ret = array_merge($ret, $join);
			}
		}
		return $ret;
	}
	
	/**
	* @return the JOIN clause of a sql statement that DataTable needs to do this join.
	*/
	function joinSql()
	{
		return 'LEFT JOIN `' . $this->joinTable . '` AS `' . $this->joinTableAlias
			. '` ON `' . $this->mainTable . '`.`' . $this->mainTableFKField . '`=`'
			. $this->joinTableAlias . '`.`' . $this->joinTableReferencedUniqueField . '`';
	}
}

?>