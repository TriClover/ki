<?php
namespace mls\ki\Setup;
use \mls\ki\Database;
use \mls\ki\Widgets\Form;

/**
* This handles the entire setup/install page.
* Use it to provide the means to check the environment health, update the DB schema,
* edit the static configuration, and so on.
*/
class Setup extends Form
{
	private $modules = array();       //All SetupModule instances being used.
	private $moduleNames = array();   //Names of SetupModule implementations to use, in order.
	private $responses = array();     //return codes from getHTML() for each module
	private $handlingErrors = array();//Contains strings if there were errors so serious no modules could be processed.
	public $siteName = NULL;
	
	/**
	* Initialize the setup.
	* @param siteName the internal name of the site, used to name the config file
	* @param modules Any application-level SetupModule implementation names (fully qualified) to include in setup.
	*/
	function __construct(string $siteName, array $modules = array())
	{
		$this->siteName = $siteName;
		$ki_mods = array(
			'\mls\ki\Setup\SmPHP',
			'\mls\ki\Setup\SmPackages',
			'\mls\ki\Setup\SmCreateConfig',
			'\mls\ki\Setup\SmAuth',
			'\mls\ki\Setup\SmDatabase',
			'\mls\ki\Setup\SmDatabaseVersion',
			'\mls\ki\Setup\SmDatabaseSchema',
			'\mls\ki\Setup\SmDatabaseData');
		$this->moduleNames = array_merge($ki_mods, $modules);
		foreach($this->moduleNames as $mn)
		{
			$this->modules[$mn] = new $mn($this);
		}
	}
	
	/**
	* Generate HTML for the page.
	* Get this HTML from outside by calling getHTML()
	*/
	protected function getHTMLInternal()
	{
		if(!empty($this->handlingErrors))
		{
			return implode('<br/>', $this->handlingErrors);
		}
		$out = '<style scoped>.setupresults{background-color:#999;margin:1em;}.setupresults td,.setupresults th{background-color:#FFF;}.success{color:green;}.failure{color:red;}.warning{color:orange;}.setupresults tr td:first-of-type+td{font-weight:bold;text-align:center;}</style>';
		$out .= '<table class="setupresults"><tr><th>Step</th><th>Status</th><th>Info</th></tr>';
		foreach($this->moduleNames as $mn)
		{
			$mod = $this->modules[$mn];
			$html = $mod->getHTML();
			$out .= '<tr><td>' . $mod->getFriendlyName() . '</td>';
			$processed = true;
			switch($this->responses[$mn])
			{
				case SetupModule::SUCCESS:
				$out .= '<td class="success">✔';
				break;
				
				case SetupModule::FAILURE:
				$out .= '<td class="failure">✘';
				break;
				
				case SetupModule::WARNING:
				$out .= '<td class="warning">⚠️';
				break;
				
				default:
				$out .= '<td>?';
				$processed = false;
			}
			$out .= '</td><td>' . ($processed?$mod->getHTML():'&nbsp;') . '</td></tr>';
		}
		$out .= '</table>';
		return $out;
	}
	
	/**
	* Do the actual setup and info gathering here.
	* Activate it from outside by calling handleParams()
	*/
	protected function handleParamsInternal()
	{
		foreach($this->moduleNames as $mn)
		{
			$this->responses[$mn] = $this->modules[$mn]->handleParams();
			if($this->responses[$mn] === SetupModule::FAILURE) break;
		}
	}
}

?>