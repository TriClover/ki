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
	* Get/set mysqli objects for database connection
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
			Log::log($failureLogLevel, 'Query preparation failed - ' . $purpose);
			return false;
		}
		
		//bind params
		if(!empty($args))
		{
			if(!$stmt->bind_param(str_repeat('s',count($args)), ...$args))
			{
				Log::log($failureLogLevel, 'Parameter binding failed - ' . $purpose . ': ' . $stmt->error);
				$stmt->close();
				return false;
			}
		}
		
		//execute
		if(!$stmt->execute())
		{
			Log::log($failureLogLevel, 'Prepared statement execution failed - ' . $purpose . ': ' . $stmt->error);
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
			Log::log($failureLogLevel, 'Getting result set handle for prepared statement failed - ' . $purpose . ': ' . $stmt->error);
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
}
?>