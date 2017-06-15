<?php
$res = chdir($_SERVER['DOCUMENT_ROOT']);

if($res === false)
{
	echo("Couldn't chdir");
	exit;
}

echo('Detecting PHP version...<br/>');
$requiredPHPVersion = '7.0.0';
if(version_compare(PHP_VERSION, $requiredPHPVersion, '<'))
{
	echo('Error: Insufficient PHP version. Requires at least <tt>' . $requiredPHPVersion
		. '</tt>. Found <tt>' . PHP_VERSION . '</tt>. Aborting.<br/>');
	exit;
}
echo('PHP Version OK.<br/>');

echo('Detecting available PHP extensions...<br/>');
$extensions = array('json', 'mysqli', 'mbstring');
$x_missing = array();
foreach($extensions as $e)
{
	if(!extension_loaded($e)) $x_missing[] = $e;
}
if(!empty($x_missing))
{
	echo('Error: Some critical PHP extensions are missing: ' . implode(', ', $x_missing)
		. '<br/>Aborting install.');
	exit;
}else{
	echo('All required PHP extensions were found.<br/>');
}
echo('<br/>');

echo('Checking for live configuration file...<br/>');
if(!file_exists('../config/ki.ini'))
{
	$res = copy('vendor/mls/ki/setup/ki.ini.template', '../config/ki.ini');
	if($res)
	{
		echo('Configuration copied from template.<br/>');
	}else{
		echo('Error: Failed to copy configuration from template. Check permissions of "ki/setup/ki.ini.template" and "../config/ki.ini"');
		exit;
	}
}else{
	echo('Configuration already set.<br/>');
}
echo('<br/>');


echo('Loading ki framework...<br/>');
require_once($_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php');
ki\init();
use function \ki\database\query;
echo('Framework loaded.<br/>');
echo('<br/>');

echo('Checking database:<br/>');
$db = \ki\db();
if($db === NULL)
{
	echo('Database connection not established. You may still need to create the database, or edit the config file to point to it. Aborting.');
	exit;
}
echo('Connected to database.<br/>');
echo('Checking database server version...<br/>');
$resVersion = query($db, 'SELECT version() AS version', array(), 'checking DB version');
if($resVersion === false)
{
	echo('Error: failed to determine DBMS version. Aborting');
	exit;
}
echo('Version OK.<br/>');
$dbVersion = $resVersion[0]['version'];
$requiredDbVersion = '10.0.12';
if(version_compare($dbVersion, $requiredDbVersion, '<'))
{
	echo('Error: Insufficient MariaDB version. Requires at least <tt>' . $requiredDbVersion
		. '</tt>. Found <tt>' . $dbVersion . '</tt>. Aborting.<br/>');
	exit;
}

$schema = file_get_contents('vendor/mls/ki/setup/schema.sql');
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
$query = 'SELECT COUNT(*) FROM information_schema.`tables` WHERE `table_schema` = "'
	. \ki\config()['db']['dbname'][0] . '" AND `table_name` IN("' . implode('","', $tables) . '")';
$res = $db->query($query);
$row = $res->fetch_array();
if($row === false)
{
	echo('Error looking for tables in DB. Aborting.');
	exit;
}
$tablecount = $row[0];
if($tablecount == count($tables))
{
	echo('All tables present.<br/>');
}else if($tablecount > 0 && $tablecount != count($tables)){
	echo('Error: schema partially installed. Fix the database manually before continuing.');
	exit;
}else{
	echo('Tables not installed yet. Installing now...<br/>');
	$db->multi_query($schema);
	while($db->more_results()) $db->next_result();
	echo('Tables installed!');
}
echo('<br/>');

?>