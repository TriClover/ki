<?php
namespace mls\ki\Setup;
use \mls\ki\Config;
use \mls\ki\Ki;

class SmCreateConfig extends SetupModule
{
	private $msg = '';
	
	public function getFriendlyName() { return 'Configuration File'; }
	
	protected function handleParamsInternal()
	{
		$liveConfigLocation = $_SERVER['DOCUMENT_ROOT'] . '/../config/' . $this->setup->siteName . '.json';
		$configTemplateLocation = dirname(__FILE__) . '/ki.json.template';
	
		if(!file_exists($liveConfigLocation))
		{
			$res = copy($configTemplateLocation, $liveConfigLocation);
			if($res)
			{
				$this->msg = 'Configuration copied from template.';
			}else{
				$this->msg = 'Error: Failed to copy configuration from template. Check permissions of "'
					. $configTemplateLocation . '" and "' . $liveConfigLocation . '"';
				return SetupModule::FAILURE;
			}
		}else{
			$this->msg = 'Configuration already set.';
		}
		
		Ki::init($this->setup->siteName);
		Config::get();
		return SetupModule::SUCCESS;
	}
	
	protected function getHTMLInternal()
	{
		return $this->msg;
	}
}

?>