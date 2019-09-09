<?php
namespace mls\ki\Setup;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Ki;
use \mls\ki\Util;

class SmDatabaseSchema extends SetupModule
{
	protected $msg = '';
	public function getFriendlyName() { return 'Database Schemas'; }
	
	protected function handleParamsInternal()
	{
		$kiSchemaFileLocation = dirname(__FILE__) . '/schema.sql';
		$appSchemaFileDir = $_SERVER['DOCUMENT_ROOT'] . '/../../config/';
		$config = Config::get();
		$db = Database::db();
		$dbSchema = Database::db('schemaCompare');
		$retVal = SetupModule::SUCCESS;

		//load schema definition
		$schema = file_get_contents($kiSchemaFileLocation);
		if($schema === false)
		{
			$this->msg = 'Failed to load framework schema file.';
			return SetupModule::FAILURE;
		}
		$expectedAppSchemas = [];
		$missingAppSchemaTitles = [];
		$appSchemas = ['main' => ''];
		foreach($config['db'] as $title => $details)
		{
			if($title == 'schemaCompare') continue;
			$testPath = $appSchemaFileDir . Ki::$siteName . '.' . $title . '.sql';
			$expectedAppSchemas[$title] = $testPath;
			$thisSchemaContent = file_get_contents($testPath);
			if($thisSchemaContent === false)
			{
				$missingAppSchemaTitles[] = $title;
				$appSchemas[$title] = '';
			}else{
				$appSchemas[$title] = $thisSchemaContent;
			}
		}
		if(!empty($missingAppSchemaTitles))
		{
			$retVal = SetupModule::WARNING;
			$this->msg .= "Some configured databases didn't have an app-level schema: "
				. implode(', ', $missingAppSchemaTitles) . '<br/>';
		}
		$unexpectedAppSchemaTitles = [];
		foreach(glob($appSchemaFileDir . Ki::$siteName . '.*.sql') as $fsItem)
		{
			if(is_dir($fsItem)) continue;
			$filename = basename($fsItem);
			$titleStart = mb_strlen(Ki::$siteName)+1;
			$title = substr($filename, $titleStart, mb_strlen($filename) - $titleStart - 4);
			if(!isset($expectedAppSchemas[$title]))
			{
				$unexpectedAppSchemaTitles[] = $title;
			}
		}
		if(!empty($unexpectedAppSchemaTitles))
		{
			$retVal = SetupModule::WARNING;
			$this->msg .= "Found some app-level schemas for this site with no corresponding DB connection configured: "
				. implode(', ', $unexpectedAppSchemaTitles) . '<br/>';
		}

		//compare current/reference schema for each defined DB
		$compareResults = [];
		foreach($appSchemas as $title => $sql)
		{
			//Run the compare
			$thisDB = Database::db($title);
			if($title == "main") $sql = $schema . $sql;
			$outCompare = $thisDB->generateDiffSQL($dbSchema, $sql);
			if(!is_array($outCompare)) {$this->msg = $outCompare; return SetupModule::FAILURE;}
			
			//If user chose to apply the sync
			if(!empty($outCompare) && !empty($_POST['runsql']))
			{
				$constructedScript = implode("\n",$outCompare);
				if(false === $thisDB->query('SET foreign_key_checks = 0',[],'Ignoring foreign keys for temp diff DB schema import')) return 'Error ignoring foreign keys for schema update';
				$res = $thisDB->runScript($constructedScript, 'Running update script from diff on DB ' . $title);
				if(false === $dbSchema->query('SET foreign_key_checks = 1',[],'Reenabling foreign keys for temp diff DB schema import')) return 'Error reenabling foreign keys after schema update';
				if(in_array(false, $res) || empty($res))
				{
					$this->msg = 'Error running update script from diff on ' . $title . ' DB - Try running it manually.<br/>';
					return SetupModule::FAILURE;
				}
				$outCompare = $thisDB->generateDiffSQL($dbSchema, $sql);
				if(!is_array($outCompare)) {$this->msg = $outCompare; return SetupModule::FAILURE;}
			}
			
			if(!empty($outCompare)) $compareResults[$title] = $outCompare;
		}

		//Display 
		if(!empty($compareResults))
		{
			$diffForm = '<fieldset><legend>Changes to update live schemas to current version</legend><pre style="height:10em;overflow:scroll;">';
			foreach($compareResults as $title => $diff)
			{
				$diffForm .= '<fieldset><legend>' . $title . '</legend>' . implode("\n", $diff) . '</fieldset>';
			}
			$diffForm .= '</pre><form method="post"><input type="submit" name="runsql" value="Run This"/></form></fieldset>';
			$this->msg .= $diffForm . 'The above differences were found between current and latest schema.<br/>';
			$retVal = SetupModule::WARNING;
		}
		
		if($this->msg == '') $this->msg = 'Schemas up to date.';
		return $retVal;
	}
	
	protected function getHTMLInternal()
	{
		return $this->msg;
	}
}