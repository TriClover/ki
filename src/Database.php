<?php
namespace mls\ki;

class Database
{
	public $connection = NULL;
	public $host = NULL;
	public $user = NULL;
	public $password = NULL;
	public $dbName = NULL;
	
	function __construct(string $host, string $user, string $password, string $dbname)
	{
		$this->host     = $host;
		$this->user     = $user;
		$this->password = $password;
		$this->dbName   = $dbname;
		
		$dbobj = new \mysqli($host, $user, $password, $dbname);
		if($dbobj->connect_errno)
		{
			Log::fatal('Failed to connect to MySQL DB `' . $dbname . '` on ' . $host
				. 'with error ' . $dbobj->connect_errno . ': ' . $dbobj->connect_error);
			exit;
		}
		$dbobj->set_charset('utf8mb4');
		$this->connection = $dbobj;
	}
	
	private static $all_connected = false;
	
	/**
	* Get/set Database objects for database connection
	* No args = return main DB (or NULL if none)
	* 1 arg  = return DB having the given title (or NULL if none)
	* 2 args = add new DB with the given title
	*/
	public static function db($title = NULL, Database $dbObj = NULL)
	{
		if($dbObj === NULL && !Database::$all_connected) Database::connect_all();
		static $all = array();
		if($title === NULL) return array_key_exists('main',$all) ? $all['main'] : NULL;
		if($dbObj === NULL) return array_key_exists($title,$all) ? $all[$title] : NULL;
		$all[$title] = $dbObj;
	}
	
	public function connectionString()
	{
		return $this->user . ':' . $this->password . '@' . $this->host;
	}

	/**
	* Execute a query using a prepared statement with the given args.
	* @param query the SQL query string to execute
	* @param args the arguments to provide to the prepared statement object corresponding to $query
	* @param purpose is used in the content of log messages on error
	* @param failureLogLevel the level at which failures should be logged
	* returns:
	*	(for SELECT) the entire result set as an array of associative arrays
	*	(for non-SELECT) the number of affected rows
	*	or FALSE on failure
	*/
	public function query(string $query, array $args, string $purpose, int $failureLogLevel = Log::ERROR)
	{
		$db = $this->connection;
		//prepare query
		$stmt = $db->prepare($query);
		if($stmt === false)
		{
			Log::log($failureLogLevel, 'Query preparation failed - ' . $purpose . ': ' . $db->error . ' QUERY: ' . $query);
			return false;
		}
		
		//bind params
		if(!empty($args))
		{
			if(!$stmt->bind_param(str_repeat('s',count($args)), ...$args))
			{
				Log::log($failureLogLevel, 'Parameter binding failed - ' . $purpose . ': ' . $stmt->error . ' QUERY: ' . $query);
				$stmt->close();
				return false;
			}
		}
		
		//execute
		if(!$stmt->execute())
		{
			Log::log($failureLogLevel, 'Prepared statement execution failed - ' . $purpose . ': ' . $stmt->error . ' QUERY: ' . $query);
			$stmt->close();
			return false;
		}
		
		//attempt to get SELECT result
		$res = $stmt->get_result();
		
		//for non-SELECT, return number of affected rows
		if($res === false && $stmt->errno === 0)
		{
			$affected = $stmt->affected_rows;
			$stmt->close();
			return $affected;
		}
		
		//handle errors in getting SELECT result
		if($res === false)
		{
			Log::log($failureLogLevel, 'Getting result set handle for prepared statement failed - ' . $purpose . ': ' . $stmt->error . ' QUERY: ' . $query);
			$stmt->close();
			return false;
		}
		
		//fetch selected data
		$data = $res->fetch_all(MYSQLI_ASSOC);
		$stmt->close();
		return $data;
	}
	
	/**
	* Execute a query using a prepared statement with the given args.
	* @param script the SQL script contents to execute
	* @param purpose is used in the content of log messages on error
	* @param failureLogLevel the level at which failures should be logged
	* @return an array of results, each result in the form returned by the query() function except that successful non-SELECT statements give TRUE rather than the number of affected rows.
	*/
	public function runScript(string $script, string $purpose, int $failureLogLevel = Log::ERROR)
	{
		$db = $this->connection;
		$res = $db->multi_query($script);
		if($res === false)
		{
			Log::log($failureLogLevel, 'Failed to run script - ' . $purpose . ': ' . $db->error);
			return [false];
		}
		$results = array();
		while($db->more_results())
		{
			$loaded = $db->next_result();
			if(!$loaded)
			{
				Log::log($failureLogLevel, 'Failed to load the result set for one of the statements in a script - ' . $purpose . ': ' . $db->error);
				$results[] = false;
				continue;
			}
			$res = $db->store_result();
			if($res === false)
			{
				if($db->errno === 0)
				{
					$results[] = true;  //Successful non-SELECT statement
				}else{
					$results[] = false; //Failed statement
					Log::log($failureLogLevel, 'Getting result set handle for one statement in a script failed - ' . $purpose . ': ' . $db->error);
				}
			}else{
				$results[] = $res->fetch_all(MYSQLI_ASSOC); //Successful SELECT statement
				$res->free();
			}
		}
		return $results;
	}

	/**
	* Create and store mysqli objects for the whole app to access,
	* using the credentials found in the config
	*/
	private static function connect_all($config = NULL)
	{
		if($config === NULL) $config = Config::get();
		foreach($config['db'] as $title => $conf)
		{
			Database::db($title, new Database($conf['host'], $conf['user'], $conf['password'], $conf['dbname']));
		}
		Database::$all_connected = true;
	}
	
	public function dropAllTables()
	{
		$script = <<<END_SCRIPT
DROP PROCEDURE IF EXISTS `drop_all_tables`;

CREATE PROCEDURE `drop_all_tables`()
BEGIN
    DECLARE _done INT DEFAULT FALSE;
    DECLARE _tableName VARCHAR(255);
    DECLARE _cursor CURSOR FOR
        SELECT table_name 
        FROM information_schema.TABLES
        WHERE table_schema = SCHEMA();
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET _done = TRUE;

    SET FOREIGN_KEY_CHECKS = 0;

    OPEN _cursor;

    REPEAT FETCH _cursor INTO _tableName;

    IF NOT _done THEN
        SET @stmt_sql = CONCAT('DROP TABLE ', _tableName);
        PREPARE stmt1 FROM @stmt_sql;
        EXECUTE stmt1;
        DEALLOCATE PREPARE stmt1;
    END IF;

    UNTIL _done END REPEAT;

    CLOSE _cursor;
    SET FOREIGN_KEY_CHECKS = 1;
END;

call drop_all_tables(); 

DROP PROCEDURE IF EXISTS `drop_all_tables`;
END_SCRIPT;
		return !in_array(false, $this->runScript($script, 'Dropping all tables'));
	}
	
	/**
	* Generates an array of SQL statements that would make this DB
	* have the same schema as another DB. Uses mysqldbcompare.
	* @param dbSchema Database object for the temp comparison DB to use as a reference (existing contents will be lost!)
	* @param schema the SQL script holding the schema definition
	* @return array of lines in a SQL script, or string if an error happened.
	*/
	public function generateDiffSQL(Database $dbSchema, string $schema)
	{
		//load schema
		if(false === $dbSchema->dropAllTables()) return 'Error preparing to load schema into temp comparison DB';
		if(false === $dbSchema->query('SET foreign_key_checks = 0',[],'Ignoring foreign keys for temp diff DB schema import')) return 'Error ignoring foreign keys for temp diff DB schema import';
		$res = $dbSchema->runScript($schema, 'Loading schema into temp comparison DB');
		if(false === $dbSchema->query('SET foreign_key_checks = 1',[],'Reenabling foreign keys for temp diff DB schema import')) return 'Error reenabling foreign keys for temp diff DB schema import';
		if(in_array(false, $res)) return 'Error loading schema into temp comparison DB';
		
		//compare main DB to comparison DB
		/* we use --skip-table-options to suppress AUTO_INCREMENT changes
		   but it also skips some useful changes like charset.
		   If mysqldbcompare ever lets you specify which table-options to check/skip, then change this.
		*/
		$cmdCompare = 'mysqldbcompare --server1=' . $this->connectionString() . ' ' . $this->dbName . ':' . $dbSchema->dbName . ' --skip-data-check --skip-row-count --skip-table-options --difftype=sql --run-all-tests --character-set=utf8mb4';
		$compareResults = Util::cmd($cmdCompare);
		if(!is_array($compareResults)) return 'Error running mysqldbcompare: ' . $compareResults;
		$outCompare = explode("\n", $compareResults['stdout']);
		$errCompare = str_replace("\n", '<br/>', $compareResults['stderr']);
		if($errCompare != '') return 'Error running mysqldbcompare:<br/><br/>' . $errCompare;
		/* we only checked stderr for errors because mysqldbcompare's exitcode is not very useful:
		   it returns the same code (1) for both "error running the command" and
		   "ran successfully and found differences"
		*/

		$missingTables = array();
		$extraTables = array();
		$previousLine = '';
		foreach($outCompare as $key => $line) //remove spurious lines in the script
		{
			$line = preg_replace('/\s+/', ' ', $line);
			//This is only needed because of a bug in mysqldbcompare; lines like this should be in a comment but aren't.
			if(mb_strpos($line, 'pass SKIP SKIP') !== false)
			{
				unset($outCompare[$key]); 
			}

			/* This is only needed because of a missing feature in mysqldbcompare;
			 it should generate an appropriate CREATE/DROP TABLE statement
			 instead of us having to detect a comment.
			 However, even if mysqldbcompare gains this feature later, we may still need to note
			 missing tables and do some of this handling so that the CREATE TABLE statements are
			 executed in the proper order so as not to cause errors because of foreign keys.
			*/
			if(substr($line, 0, 8) == '# TABLE:')
			{
				//detect if this is a missing or extra table
				$liveNamePos = mb_strpos($previousLine, $this->dbName);
				$compNamePos = mb_strpos($previousLine, $dbSchema->dbName);
				$isMissing = $compNamePos < $liveNamePos;
				if($isMissing)
				{
					$missingTables[] = substr($line, 9);
				}else{
					$extraTables[] = substr($line, 9);
				}
				unset($outCompare[$key]);
				continue; //avoid setting this as the "previous line" since the missing/extra tables are grouped
			}
			
			
			if    (empty($line)   ) unset($outCompare[$key]); //remove blank lines
			elseif($line[0] == '#') unset($outCompare[$key]); //remove comments
			
			$previousLine = $line;
		}
		//grab the original CREATE TABLE statements from the script for tables noted as missing by mysqldbcompare
		$schema = explode(';', $schema);
		$foundTables = [];
		foreach($missingTables as $table)
		{
			$found = false;
			foreach($schema as $index => $query)
			{
				$query = trim($query) . ';';
				if(Util::contains($query, 'CREATE TABLE `' . $table . '`'))
				{
					$found = true;
					$foundTables[$index] = $query; //keep track in a way that preserves their original order
					break;
				}
			}
			if(!$found)
			{
				return 'table <tt>' . $table . '</tt> not found in the script it came from';
			}
		}
		ksort($foundTables);
		foreach($foundTables as $query)
		{
			$outCompare[] = $query;
		}
		
		//generate DROP TABLE statements for tables noted as extraneous by mysqldbcompare
		foreach($extraTables as $table)
		{
			$outCompare[] = 'DROP TABLE `' . $table . '`;';
		}
		return $outCompare;
	}
	
	/**
	* @param val input value
	* @return output from mysqli->real_escape_string
	*/
	function esc($val)
	{
		return $this->connection->real_escape_string($val);
	}
}
?>