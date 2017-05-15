<?php
namespace ki;

\ini_set("default_charset", "UTF-8");
\mb_internal_encoding("UTF-8");
\mb_http_output('UTF-8');
\iconv_set_encoding("internal_encoding", "UTF-8");
\iconv_set_encoding("output_encoding", "UTF-8");

/**
* Get/set mysqli objects for database connection
* No args = return main DB (or NULL if none)
* 1 arg  = return DB having the given title (or NULL if none)
* 2 args = add new DB with the given title
*/
function db($title = NULL, $connection = NULL)
{
	static $all = array();
	if($title === NULL) return array_key_exists(0,$all) ? $all[0] : NULL;
	if($connection === NULL) return array_key_exists($title,$all) ? $all[$title] : NULL;
	$all[] = $connection;
	$all[$title] = $connection;
}

namespace ki\database;

/**
* Create mysqli object with the given credentials
*/
function connect($host, $user, $password, $dbname)
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
function connect_all($config = NULL)
{
	if($config === NULL) $config = \ki\config();
	if(!is_array($config['db']['title'])
		&& !is_array($config['db']['host'])
		&& !is_array($config['db']['user'])
		&& !is_array($config['db']['password'])
		&& !is_array($config['db']['dbname']))
	{
		\ki\db($config['db']['title'],
			connect($config['db']['host'],
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
			\ki\db($config['db']['title'][$i],
				connect($config['db']['host'][$i],
					$config['db']['user'][$i],
					$config['db']['password'][$i],
					$config['db']['dbname'][$i]
				)
			);
		}
	}
}
?>