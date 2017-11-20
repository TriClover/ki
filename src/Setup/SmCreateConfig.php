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
		$liveConfigLocation = '../config/' . $this->setup->siteName . '.json'; //Live config, where the application will look for it. Relative to docRoot
		$configTemplateLocation = 'vendor/mls/ki/src/Setup/ki.json.template'; //Config template, only this installer will need it. Relative to docRoot.
	
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