<?php
namespace mls\ki\Setup;

class SmPHP extends SetupModule
{
	private $msg = '';
	
	public function getFriendlyName() { return 'PHP'; }
	
	protected function handleParamsInternal()
	{
		$requiredPHPVersion = '7.0.0';                     //PHP minimum acceptable version
		$extensions = array('json', 'mysqli', 'mbstring'); //names of PHP extensions required by the code
		
		if(version_compare(PHP_VERSION, $requiredPHPVersion, '<'))
		{
			$this->msg = 'Insufficient PHP version. Requires at least <tt>' . $requiredPHPVersion
				. '</tt>. Found <tt>' . PHP_VERSION . '</tt>.';
			return SetupModule::FAILURE;
		}
		
		$x_missing = array();
		foreach($extensions as $e)
		{
			if(!extension_loaded($e)) $x_missing[] = $e;
		}
		if(!empty($x_missing))
		{
			$this->msg = 'Some critical PHP extensions are missing: ' . implode(', ', $x_missing);
			return SetupModule::FAILURE;
		}
		
		$this->msg = 'PHP version and extensions OK.';
		return SetupModule::SUCCESS;
	}
	
	protected function getHTMLInternal()
	{
		return $this->msg;
	}
}

?>