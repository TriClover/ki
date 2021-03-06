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
		/*
			We're copying stuff from other composer packages because
			oomphinc/composer-installers-extender is broken
			
			We're copying stuff from static folders containing things
			that should have been loaded from composer because
			ansible can't handle when composer asks for a github personal access key
			
			See removedFromComposer.json
		*/
		$origins = [
			'ki'          => dirname(__FILE__) . '/../../static/ki/*',
			'selectivizr' => dirname(__FILE__) . '/../../static/selectivizr/*',
			'webshim'     => dirname(__FILE__) . '/../../static/webshim/*',
			'jquery'      => dirname(__FILE__) . '/../../../../bower-asset/jquery/dist/*',
			'jquery-ui'   => dirname(__FILE__) . '/../../../../bower-asset/jquery-ui/*',
			'multiselect' => dirname(__FILE__) . '/../../../../bower-asset/jquery-ui-multiselect-widget/*'
		];
		
		
		$libsDir = $config['general']['staticDir'] . '/lib';
		mkdir($libsDir,0777,true);
		$rsOptions = ['archive' => true];
		$rsync = new Rsync($rsOptions);
		try{
			foreach($origins as $outName => $origin)
			{
				//sync has no return statement, but is supposed to throw an exception on errors
				//In practice, on error it seems to write logs but not throw anything!
				$rsync->sync($origin, $libsDir.'/'.$outName);
			}
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