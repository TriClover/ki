<?php
namespace mls\ki;

class Database
{
	public $connection = NULL;
	
	function __construct(\mysqli $connection)
	{
		$this->connection = $connection;
	}
	
	private static $all_connected = false;
	
	/**
	* Get/set mysqli objects for database connection
	* No args = return main DB (or NULL if none)
	* 1 arg  = return DB having the given title (or NULL if none)
	* 2 args = add new DB with the given title
	*/
	public static function db($title = NULL, $connection = NULL)
	{
		if($connection === NULL && !Database::$all_connected) Database::connect_all();
		static $all = array();
		if($title === NULL) return array_key_exists(0,$all) ? new Database($all[0]) : NULL;
		if($connection === NULL) return array_key_exists($title,$all) ? new Database($all[$title]) : NULL;
		$all[] = $connection;
		$all[$title] = $connection;
	}

	/**
	* Execute a query using a prepared statement with the given args.
	* $purpose is used in log messages on error
	* returns:
	*	(for SELECT) the entire result set as an array of associative arrays
	*	(for non-SELECT) the number of affected rows
	*	or FALSE on failure
	*/
	public function query(string $query, array $args, string $purpose)
	{
		$db = $this->connection;
		//prepare query
		$stmt = $db->prepare($query);
		if($stmt === false)
		{
			Log::error('Query preparation failed - ' . $purpose);
			return false;
		}
		
		//bind params
		if(!empty($args))
		{
			if(!$stmt->bind_param(str_repeat('s',count($args)), ...$args))
			{
				Log::error('Parameter binding failed - ' . $purpose . ': ' . $stmt->error);
				$stmt->close();
				return false;
			}
		}
		
		//execute
		if(!$stmt->execute())
		{
			Log::error('Prepared statement execution failed - ' . $purpose . ': ' . $stmt->error);
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
			Log::error('Getting result set handle for prepared statement failed - ' . $purpose . ': ' . $stmt->error);
			$stmt->close();
			return false;
		}
		
		//fetch selected data
		$data = $res->fetch_all(MYSQLI_ASSOC);
		$stmt->close();
		return $data;
	}

	/**
	* Create mysqli object with the given credentials
	*/
	private static function connect($host, $user, $password, $dbname)
	{
		$dbobj = new \mysqli($host, $user, $password, $dbname);
		if($dbobj->connect_errno)
		{
			\ki\Log::fatal('Failed to connect to MySQL DB `' . $dbname . '` on ' . $host
				. 'with error ' . $dbobj->connect_errno . ': ' . $dbobj->connect_error);
			exit;
		}
		$dbobj->set_charset('utf8mb4');
		return $dbobj;
	}

	/**
	* Create and store mysqli objects for the whole app to access,
	* using the credentials found in the config
	*/
	private static function connect_all($config = NULL)
	{
		if($config === NULL) $config = Config::get();
		if(!is_array($config['db']['title'])
			&& !is_array($config['db']['host'])
			&& !is_array($config['db']['user'])
			&& !is_array($config['db']['password'])
			&& !is_array($config['db']['dbname']))
		{
			Database::db($config['db']['title'],
				Database::connect($config['db']['host'],
					$config['db']['user'],
					$config['db']['password'],
					$config['db']['dbname']
				)
			);
		}elseif(is_array($config['db']['title'])
			&& is_array($config['db']['host'])
			&& is_array($config['db']['user'])
			&& is_array($config['db']['password'])
			&& is_array($config['db']['dbname']))
		{
			for($i = 0; $i < count($config['db']['title']); ++$i)
			{
				Database::db($config['db']['title'][$i],
					Database::connect($config['db']['host'][$i],
						$config['db']['user'][$i],
						$config['db']['password'][$i],
						$config['db']['dbname'][$i]
					)
				);
			}
		}
		Database::$all_connected = true;
	}
}
?>