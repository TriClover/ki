<?php
namespace mls\ki\Setup;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Ki;
use \mls\ki\Util;

class SmDatabase extends SetupModule
{
	protected $msg = '';
	protected $showForm = false;
	
	public function getFriendlyName() { return 'Database'; }
	
	protected function handleParamsInternal()
	{
		$requiredDbVersion = '10.0.12';                               //MariaDB minimum acceptable version
		$kiSchemaFileLocation = 'vendor/mls/ki/src/Setup/schema.sql'; //schema SQL file with the framework tables
		$appSchemaFileLocation = '../config/schema.sql';              //schema SQL file with the app tables
		$retVal = SetupModule::SUCCESS;
		$this->msg = '';
		$config = Config::get();
		$delayedFail = false; //set true if a failure was encountered but we still want to run the rest of the checks instead of bailing completely.
		
		//process changes to config
		if(!empty($_POST['dbsubmit'])
			&& !empty($_POST['host']) && !empty($_POST['user']) && !empty($_POST['password']) && !empty($_POST['dbname'])
			&& !empty($_POST['SChost']) && !empty($_POST['SCuser']) && !empty($_POST['SCpassword']) && !empty($_POST['SCdbname']))
		{
			if(!isset($config['db'])) $config['db'] = [];
			$config['db']['main'] = ['host' => $_POST['host'], 'user' => $_POST['user'], 'password' => $_POST['password'], 'dbname' => $_POST['dbname'] ];
			$config['db']['schemaCompare'] = ['host' => $_POST['SChost'], 'user' => $_POST['SCuser'], 'password' => $_POST['SCpassword'], 'dbname' => $_POST['SCdbname'] ];
			$this->setup->config = $config;
			Config::rewrite($config);
		}
		
		//check if config is missing DB config for main DB or schemaCompare DB
		if(empty($config['db'])
			|| empty($config['db']['main']) || empty($config['db']['schemaCompare'])
			|| empty($config['db']['main']['host']) || empty($config['db']['main']['user']) || empty($config['db']['main']['password']) || empty($config['db']['main']['dbname'])
			|| empty($config['db']['schemaCompare']['host']) || empty($config['db']['schemaCompare']['user']) || empty($config['db']['schemaCompare']['password']) || empty($config['db']['schemaCompare']['dbname']))
		{
			$this->msg = 'Incomplete database configuration.';
			$this->showForm = true;
			return SetupModule::FAILURE;
		}

		//check that we can connect
		$connectFailMsgs = '';
		$dbobj = new \mysqli($config['db']['main']['host'], $config['db']['main']['user'], $config['db']['main']['password']);
		if($dbobj->connect_errno)
		{
			$connectFailMsgs .= 'Failed to connect to server for "main" with error ' . $dbobj->connect_errno . ': ' . $dbobj->connect_error . '<br/>';
			$dbobj->close();
		}
		$dbobjSC = new \mysqli($config['db']['schemaCompare']['host'], $config['db']['schemaCompare']['user'], $config['db']['schemaCompare']['password']);
		if($dbobjSC->connect_errno)
		{
			$connectFailMsgs .= 'Failed to connect to server for "schemaCompare" with error ' . $dbobjSC->connect_errno . ': ' . $dbobjSC->connect_error . '<br/>';
			$dbobjSC->close();
		}
		if(!empty($connectFailMsgs))
		{
			$this->msg = $connectFailMsgs;
			$this->showForm = true;
			return SetupModule::FAILURE;
		}
		$dbobj->set_charset('utf8mb4');
		$dbobjSC->set_charset('utf8mb4');
		
		//check that databases are present
		$selectRes   = $dbobj->select_db($config['db']['main']['dbname']);
		$selectResSC = $dbobjSC->select_db($config['db']['schemaCompare']['dbname']);
		if(!$selectRes || !$selectResSC)
		{
			$this->msg = '';
			if(!$selectRes) $this->msg .= 'Database name not found for "main"<br/>';
			if(!$selectResSC) $this->msg .= 'Database name not found for "schemaCompare"<br/>';
			$this->showForm = true;
			return SetupModule::FAILURE;
		}
		$dbobj->close();
		$dbobjSC->close();
		
		//Set up database connections via main Database class
		$db = Database::db();
		$dbSchema = Database::db('schemaCompare');
		if($db === NULL || $dbSchema === NULL)
		{
			$this->msg = 'Full database connections failed after test was successful. You may have found a bug. The log may have more info.';
			$this->showForm = true;
			return SetupModule::FAILURE;
		}
		//check MariaDB version
		$resVersion = $db->query('SELECT version() AS version', array(), 'checking DB version');
		$resVersionSC = $dbSchema->query('SELECT version() AS version', array(), 'checking DB version');
		if($resVersion === false || $resVersionSC === false)
		{
			$this->msg = 'Failed to determine DBMS version.';
			return SetupModule::FAILURE;
		}
		$dbVersion = $resVersion[0]['version'];
		$dbVersionSC = $resVersionSC[0]['version'];
		if(version_compare($dbVersion, $requiredDbVersion, '<') || version_compare($dbVersionSC, $requiredDbVersion, '<'))
		{
			$this->msg = 'Insufficient MariaDB version. Requires at least <tt>' . $requiredDbVersion
				. '</tt>. Found <tt>' . $dbVersion . '</tt> and <tt>' . $dbVersionSC . '</tt>.';
			return SetupModule::FAILURE;
		}
		//load schema definition
		$schema = file_get_contents($kiSchemaFileLocation);
		$appSchema = file_get_contents($appSchemaFileLocation);
		if($schema === false)
		{
			$this->msg = 'Failed to load framework schema file.';
			return SetupModule::FAILURE;
		}
		if($appSchema === false)
		{
			$this->msg = 'Failed to load app schema file. Continuing with only framework schema.';
			$retVal = SetupModule::WARNING;
		}
		if(!$dbSchema->dropAllTables()) {$this->msg = 'Error loading schema into temp comparison DB'; return SetupModule::FAILURE;}
		$res = $dbSchema->runScript($schema, 'Loading framework schema into temp comparison DB');
		$res2 = $dbSchema->runScript($appSchema, 'Loading app schema into temp comparison DB');
		if(in_array(false, $res)) {$this->msg = 'Error loading framework schema into temp comparison DB'; return SetupModule::FAILURE;}
		if(in_array(false, $res2))
		{
			$this->msg = 'App schema script did not run. Ignore if the app has no tables outside the framework.';
			$retVal = SetupModule::WARNING;
		}

		//Compare main DB to reference and generate update sql
		$outCompare = SmDatabase::generateDiffSQL($db, $dbSchema, $schema, $appSchema);
		if(!is_array($outCompare)) {$this->msg = $outCompare; return SetupModule::FAILURE;}
		if(!empty($outCompare) && !empty($_POST['runsql']))
		{
			$constructedScript = implode("\n",$outCompare);
			$res = $db->runScript($constructedScript, 'Running update script from diff on main DB');
			if(in_array(false, $res) || empty($res))
			{
				$this->msg = 'Error running update script from diff on main DB - Try running it manually.<br/>';
				$delayedFail = true;
			}
			$outCompare = SmDatabase::generateDiffSQL($db, $dbSchema, $schema, $appSchema);
			if(!is_array($outCompare)) {$this->msg = $outCompare; return SetupModule::FAILURE;}
		}
		if(!empty($outCompare))
		{
			$outCompare = '<fieldset><legend>Changes to update live schema to current version</legend><pre style="height:10em;overflow:scroll;">'
				. implode("\n", $outCompare)
				. '</pre><form method="post"><input type="submit" name="runsql" value="Run This"/></form></fieldset>';
			$this->msg .= $outCompare . 'The above differences were found between current and latest schema.<br/>';
			$retVal = SetupModule::WARNING;
		}else{
			//make sure root account is in the DB so it can be used in the "full" auth system
			$res = $db->query('SELECT `id` FROM `ki_users` WHERE `username`="root" LIMIT 1',array(),'checking root account<br/>');
			if($res === false)
			{
				$this->msg = 'Database Error checking root account.';
				return SetupModule::WARNING;
			}
			if(empty($res))
			{
				$res = $db->query('INSERT INTO `ki_users` SET `username`="root",`email`="no",`email_verified`=1,`password_hash`="no",`enabled`=1,`last_active`=NULL,`lockout_until`=NULL', array(), 'creating root user');
				if($res === false)
				{
					$this->msg = 'Database error creating root account.';
					return SetupModule::WARNING;
				}
			}
		}
		if($this->msg == '') $this->msg = 'Database ready.';
		if($delayedFail) $retVal = SetupModule::FAILURE;
		return $retVal;
	}
	
	protected function getHTMLInternal()
	{
		$config = Config::get()['db'];
		
		$out = $this->msg;
		$configForm = '<style scoped>label{display:block;width:15em;clear:both;}input[type=text]{float:right;}</style>'
			. '<br/><form method="post">'
			. '<fieldset><legend>Main</legend>'
			. '<label>Hostname: <input type="text" name="host"     value="' . $config['main']['host'] . '" required/></label>'
			. '<label>Username: <input type="text" name="user"     value="' . $config['main']['user'] . '" required/></label>'
			. '<label>Password: <input type="text" name="password" value="' . $config['main']['password'] . '" required/></label>'
			. '<label>Database: <input type="text" name="dbname"   value="' . $config['main']['dbname'] . '" required/></label>'
			. '</fieldset><fieldset><legend>schemaCompare</legend>'
			. '<label>Hostname: <input type="text" name="SChost"     value="' . $config['schemaCompare']['host'] . '" required/></label>'
			. '<label>Username: <input type="text" name="SCuser"     value="' . $config['schemaCompare']['user'] . '" required/></label>'
			. '<label>Password: <input type="text" name="SCpassword" value="' . $config['schemaCompare']['password'] . '" required/></label>'
			. '<label>Database: <input type="text" name="SCdbname"   value="' . $config['schemaCompare']['dbname'] . '" required/></label>'
			. '</fieldset>'
			. '<input type="submit" name="dbsubmit" value="Save"/></form>';
		if($this->showForm)
		{
			$out .= $configForm;
		}
		return $out;
	}
	
	/**
	* Generates an array of SQL statements that would make the main DB
	* have the same schame as the schemaCompare DB. Uses mysqldbcompare
	* @param db Database object for the "main" DB
	* @param dbSchema Database object for the "schemaCompare" DB
	* @param schema the SQL script holding the schema definition for the framework
	* @param appSchema the SQL script holding the schema definition for extra tables required by the app using the framework
	* @return array of lines in a SQL script, or string if an error happened.
	*/
	protected static function generateDiffSQL(Database $db, Database $dbSchema, string $schema, string $appSchema)
	{
		//compare main DB to comparison DB
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
				continue; //avoid setting this as the "previous line" since the missing/extra tables are grouped
			}
			
			//remove blank lines
			if(empty($line)) unset($outCompare[$key]);
			
			//remove comments
			if($line[0] == '#') unset($outCompare[$key]);
			
			$previousLine = $line;
		}
		//grab the original CREATE TABLE statements from the script for tables noted as missing by mysqldbcompare
		$schemas = $schema . $appSchema;
		$schemas = explode(';', $schemas);
		$foundTables = [];
		foreach($missingTables as $table)
		{
			$found = false;
			foreach($schemas as $index => $query)
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
}

?>