<?php
//config
$requiredPHPVersion = '7.0.0';                                   //PHP minimum acceptable version
$extensions = array('json', 'mysqli', 'mbstring');               //names of PHP extensions required by the code
$requiredDbVersion = '10.0.12';                                  //MariaDB minimum acceptable version
$docRoot = $_SERVER['DOCUMENT_ROOT'];                            //where most operations will be done from
$liveConfigLocation = '../config/ki.ini';                        //Live config, where the application will look for it. Relative to docRoot
$configTemplateLocation = 'vendor/mls/ki/setup/ki.ini.template'; //Config template, only this installer will need it. Relative to docRoot.
$kiSchemaFileLocation = 'vendor/mls/ki/setup/schema.sql';        //schema SQL file with the framework tables
$autoloaderLocAbsolute = $docRoot . '/vendor/autoload.php';      //Composer autoloader script

//possible icons: green check, red X, yellow warn, info, unknown (previous errors), locked (need login)

$res = chdir($docRoot);
if($res === false)
{
	echo("Couldn't chdir");
	exit;
}

echo('Detecting PHP version...<br/>');
if(version_compare(PHP_VERSION, $requiredPHPVersion, '<'))
{
	echo('Error: Insufficient PHP version. Requires at least <tt>' . $requiredPHPVersion
		. '</tt>. Found <tt>' . PHP_VERSION . '</tt>. Aborting.<br/>');
	exit;
}
echo('PHP Version OK.<br/>');

echo('Detecting available PHP extensions...<br/>');
$x_missing = array();
foreach($extensions as $e)
{
	if(!extension_loaded($e)) $x_missing[] = $e;
}
if(!empty($x_missing))
{
	echo('Error: Some critical PHP extensions are missing: ' . implode(', ', $x_missing) . '<br/>Aborting install.');
	exit;
}else{
	echo('All required PHP extensions were found.<br/>');
}
echo('<br/>');

echo('Checking for live configuration file...<br/>');
if(!file_exists($liveConfigLocation))
{
	$res = copy($configTemplateLocation, $liveConfigLocation);
	if($res)
	{
		echo('Configuration copied from template.<br/>');
	}else{
		echo('Error: Failed to copy configuration from template. Check permissions of "'
			. $configTemplateLocation . '" and "' . $liveConfigLocation . '"');
		exit;
	}
}else{
	echo('Configuration already set.<br/>');
}
echo('<br/>');


echo('Loading ki framework...<br/>');
require_once($autoloaderLocAbsolute);
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Ki;
use \mls\ki\Security\Request;
Ki::init();
$config = Config::get();
echo('Framework loaded.<br/>');
echo('<br/>');

echo('Checking database:<br/>');
$db = Database::db();
if($db === NULL)
{
	echo('Database connection not established. You may still need to create the database, or edit the config file to point to it. Aborting.');
	exit;
}
echo('Connected to database.<br/>');
echo('Checking database server version...<br/>');
$resVersion = $db->query('SELECT version() AS version', array(), 'checking DB version');
if($resVersion === false)
{
	echo('Error: failed to determine DBMS version. Aborting');
	exit;
}
echo('Version OK.<br/>');
$dbVersion = $resVersion[0]['version'];
if(version_compare($dbVersion, $requiredDbVersion, '<'))
{
	echo('Error: Insufficient MariaDB version. Requires at least <tt>' . $requiredDbVersion
		. '</tt>. Found <tt>' . $dbVersion . '</tt>. Aborting.<br/>');
	exit;
}

$schema = file_get_contents($kiSchemaFileLocation);
if($schema === false)
{
	echo('Error: failed to load schema file. Aborting');
	exit;
}
$tables = array();
$res = preg_match_all('/CREATE TABLE \`(\w+)\` \(/', $schema, $tables);
if($res === false || $res < 1)
{
	echo('Error: unable to find table names in schema file. Aborting');
	exit;
}
$tables = $tables[1];
$query = 'SELECT COUNT(*) AS tbls FROM information_schema.`tables` WHERE `table_schema` = "'
	. $config['db']['dbname'][0] . '" AND `table_name` IN("' . implode('","', $tables) . '")';
$res = $db->query($query, array(), 'checking DB against canonical schema');
if($res === false || empty($res))
{
	echo('Error looking for tables in DB. Aborting.');
	exit;
}
$tablecount = $res[0]['tbls'];
if($tablecount == count($tables))
{
	echo('All tables present.<br/>');
}else if($tablecount > 0 && $tablecount != count($tables)){
	echo('Error: schema partially installed. Fix the database manually before continuing.');
	exit;
}else{
	echo('Tables not installed yet. Installing now...<br/>');
	$dbc = $db->connection;
	$dbc->multi_query($schema);
	while($dbc->more_results()) $dbc->next_result();
	echo('Tables installed!<br/>');
}

echo('<br/>Checking root access...<br/>');
if(empty($config['root']['root_ip']))
{
	Config::set('root','root_ip',$_SERVER['REMOTE_ADDR']);
	echo('Root account IP now set to your current IP.<br/>');
}else{
	if($config['root']['root_ip'] == $_SERVER['REMOTE_ADDR'])
	{
		echo('Root account IP already set to your IP.<br/>');
	}else{
		echo('Root account IP already set to a different IP than your current one.<br/>');
	}
}
if(empty($config['root']['enable_root']) || !$config['root']['enable_root'])
{
	echo('Root account disabled. (This is good unless you need it to create some users.)<br/>');
}else{
	echo('Root account enabled. Once you have created a normal user account you should probably disable root.<br/>');
}
if(empty($config['root']['root_password']))
{
	echo('Root account has no password. You must set one in the config file before you can log in with it.<br/>');
}else{
	echo('Root account has a password.<br/>');
}
$res = $db->query('SELECT `id` FROM `ki_users` WHERE `username`="root" LIMIT 1',array(),'checking root account<br/>');
if($res === false)
{
	echo('Database Error checking root account.<br/>');
	exit;
}
if(empty($res))
{
	$res = $db->query('INSERT INTO `ki_users` SET `username`="root",`email`="no",`email_verified`=1,`password_hash`="no",`enabled`=1,`last_active`=NULL,`lockout_until`=NULL', array(), 'creating root user');
	if($res === false)
	{
		echo('Database error creating root account.<br/>');
		exit;
	}else{
		echo('Root account created.<br/>');
	}
}else{
	echo('Root account exists in DB.<br/>');
}

echo('<br/>Installation complete.<br/>');
?>