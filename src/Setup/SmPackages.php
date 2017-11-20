<?php
namespace mls\ki\Setup;

class SmPackages extends SetupModule
{
	private $errors = array();
	
	public function getFriendlyName() { return 'System Packages'; }
	
	protected function handleParamsInternal()
	{
		$packages = array('mysql-utilities' => '1.6.5');

		foreach($packages as $pName => $pVer)
		{
			$foundGoodVer = false;
			$cmdCheckVer = 'rpm -q ' . $pName;
			$outVer = array();
			exec($cmdCheckVer, $outVer);
			if(empty($outVer))
			{
				$this->errors[] = '<tt>' . $pName . '</tt> not found.';
			}
			foreach($outVer as $ver)
			{
				//version string example:
				//mysql-utilities-1.6.5-1.el7.noarch
				$start = mb_strlen($pName . '-');
				$ver = mb_substr($ver, $start);
				$len = mb_strpos($ver, '-');
				$ver = mb_substr($ver, 0, $len);
				if(version_compare($ver, $pVer, '>='))
				{
					$foundGoodVer = true;
					break;
				}
			}
			if(!$foundGoodVer)
			{
				$this->errors[] = '<tt>' . $pName . '</tt> out of date (<tt>' . $ver . ' < ' . $pVer . '</tt>)';
			}
		}
		if(empty($this->errors))
		{
			return SetupModule::SUCCESS;
		}else{
			return SetupModule::FAILURE;
		}
	}
	
	protected function getHTMLInternal()
	{
		return implode('<br/>', $this->errors);
	}
}

?>