<?php
namespace mls\ki\Setup;
use \mls\ki\Config;
use \mls\ki\Database;

class SmDatabase extends SetupModule
{
	protected $msg = '';
	protected $showForm = false;
	
	public function getFriendlyName() { return 'Database Connection'; }
	
	protected function handleParamsInternal()
	{
		$config = Config::get();
		
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

		$this->msg = 'Database connected.';
		return SetupModule::SUCCESS;
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
}

?>