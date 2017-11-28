<?php
namespace mls\ki\Setup;
use \mls\ki\Database;

class SmDatabaseVersion extends SetupModule
{
	protected $msg = '';
	public function getFriendlyName() { return 'MariaDB Version'; }
	
	protected function handleParamsInternal()
	{
		$db = Database::db();
		$dbSchema = Database::db('schemaCompare');
		
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
		return SetupModule::SUCCESS;
	}
	
	protected function getHTMLInternal()
	{
		return $this->msg;
	}
}