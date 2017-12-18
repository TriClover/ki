<?php
namespace mls\ki\Setup;
use \mls\ki\Config;
use \mls\ki\Ki;
use \AFM\Rsync\Rsync;

class SmInstallStatics extends SetupModule
{
	private $msg = '';
	
	public function getFriendlyName() { return 'Install static resources'; }
	
	protected function handleParamsInternal()
	{
		$config = Config::get();
		$origin = dirname(__FILE__) . '/../../static/*';
		$target = $config['general']['staticDir'];
		$rsOptions = ['archive' => true];
		$rsync = new Rsync($rsOptions);
		try{
			$rsync->sync($origin, $target);
		}catch(Exception $e){
			$this->msg = 'Failed to install statics to ' . $target;
			return SetupModule::FAILURE;
		}
		return SetupModule::SUCCESS;
	}
	
	protected function getHTMLInternal()
	{
		return $this->msg;
	}
}

?>