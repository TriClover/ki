<?php
//config
$requiredPHPVersion = '7.0.0';                                   //PHP minimum acceptable version
$extensions = array('json', 'mysqli', 'mbstring');               //names of PHP extensions required by the code
$requiredDbVersion = '10.0.12';                                  //MariaDB minimum acceptable version
$docRoot = $_SERVER['DOCUMENT_ROOT'];                            //where most operations will be done from
$liveConfigLocation = '../config/ki.ini';                        //Live config, where the application will look for it. Relative to docRoot
$configTemplateLocation = 'vendor/mls/ki/setup/ki.ini.template'; //Config template, only this installer will need it. Relative to docRoot.
$kiSchemaFileLocation = 'vendor/mls/ki/setup/schema.sql';        //schema SQL file with the framework tables
$appSchemaFileLocation = '../config/schema.sql';                 //schema SQL file with the app tables
$autoloaderLocAbsolute = $docRoot . '/vendor/autoload.php';      //Composer autoloader script
$packageManager = 'yum';                                         //Yum
$requiredMysqlUtilitiesVersion = '1.6.5';

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

echo('Checking version of mysql-utilities...<br/>');
if($packageManager == 'yum')
{
	$foundGoodVer = false;
	$cmdCheckVer = 'rpm -q mysql-utilities';
	$outVer = array();
	exec($cmdCheckVer, $outVer);
	if(empty($outVer))
	{
		echo('mysql-utilities not found.');
		exit;
	}
	foreach($outVer as $ver)
	{
		//version string form:
		//mysql-utilities-1.6.5-1.el7.noarch
		$start = mb_strlen('mysql-utilities-');
		$ver = mb_substr($ver, $start);
		$len = mb_strpos($ver, '-');
		$ver = mb_substr($ver, 0, $len);
		if(version_compare($ver, $requiredMysqlUtilitiesVersion, '>='))
		{
			$foundGoodVer = true;
			break;
		}
	}
	if(!$foundGoodVer)
	{
		echo('mysql-utilities is too out of date. Minimum version: ' . $requiredMysqlUtilitiesVersion);
		exit;
	}else{
		echo('Sufficient version found.');
	}
}else{
	echo('Unsupported package manager.');
	exit;
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
$appSchema = file_get_contents($appSchemaFileLocation);
if($schema === false)
{
	echo('Error: failed to load framework schema file. Aborting');
	exit;
}
if($appSchema === false)
{
	echo('Warning: failed to load app schema file. Continuing with only framework schema.');
}
$dbSchema = Database::db('schemaCompare');
if(!$dbSchema->dropAllTables()) {echo('Error loading schema into temp comparison DB'); exit;}
$res = $dbSchema->runScript($schema, 'Loading framework schema into temp comparison DB');
$res2 = $dbSchema->runScript($appSchema, 'Loading app schema into temp comparison DB');
if(in_array(false, $res)) {echo('Error loading schema into temp comparison DB'); exit;}
if(in_array(false, $res2)) {echo('Error loading schema into temp comparison DB'); exit;}
/* we use --skip-table-options to suppress AUTO_INCREMENT changes
   but it also skips some useful changes like charset.
   If mysqldbcompare ever lets you specify which table-options to check/skip, then change this.
*/
$cmdCompare = 'mysqldbcompare --server1=' . $db->connectionString() . ' ' . $db->dbName . ':' . $dbSchema->dbName . ' --skip-data-check --skip-row-count --skip-table-options --difftype=sql --run-all-tests --character-set=utf8mb4';
$outCompare = array();
exec($cmdCompare, $outCompare);
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

	//This is only needed because of a missing feature in mysqldbcompare; it should generate an appropriate CREATE/DROP TABLE statement instead of us having to detect a comment
	if(substr($line, 0, 8) == '# TABLE:')
	{
		//detect if this is a missing or extra table
		$liveNamePos = mb_strpos($previousLine, $db->dbName);
		$compNamePos = mb_strpos($previousLine, $dbSchema->dbName);
		$isMissing = $compNamePos < $liveNamePos;
		if($isMissing)
		{
			$missingTables[] = substr($line, 9);
		}else{
			$extraTables[] = substr($line, 9);
		}
		unset($outCompare[$key]);
	}
	
	//remove blank lines
	if(empty($line)) unset($outCompare[$key]);
	
	//remove comments
	if($line[0] == '#') unset($outCompare[$key]);
	
	$previousLine = $line;
}
//grab the original CREATE TABLE statements from the script for tables noted as missing by mysqldbcompare
$schemas = $schema . $appSchema;
foreach($missingTables as $table)
{
	$matches = array();
	$results = preg_match('/CREATE TABLE `' . $table . '`[^;]+;/', $schemas, $matches);
	if($results>0)
	{
		$statement = $matches[0]."\n";
		$outCompare[] = $statement;
	}else{
		echo('Error: table <tt>' . $table . '</tt> not found in the script it came from');
		exit;
	}
}
//generate DROP TABLE statements for tables noted as extraneous by mysqldbcompare
foreach($extraTables as $table)
{
	$outCompare[] = 'DROP TABLE `' . $table . '`;';
}

if(!empty($outCompare))
{
	$outCompare = '<fieldset><legend>Changes to update live schema to current version</legend><pre>'
		. implode("\n", $outCompare)
		. '</pre></fieldset>';
	echo($outCompare);
}else{
	echo('Schema matches current version.');
}
echo('<br/>');

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