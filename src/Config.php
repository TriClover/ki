<?php
namespace mls\ki;

class Config
{
	public static function get()
	{
		static $conf = NULL;
		if($conf === NULL)
		{
			$conf = array();
			$bootstrap = $_SERVER['DOCUMENT_ROOT'] . '/../config/ki.ini';
			$conf = parse_ini_file($bootstrap, true, INI_SCANNER_RAW);
			$conf = Config::processDefaults($conf);
		}
		return $conf;
	}

	private static function processDefaults($conf)
	{
		if($conf === false) return $conf;
		
		$conf['general']['componentsFilePath'] = trim($conf['general']['componentsFilePath']);
		if(empty($conf['general']['componentsFilePath']))
		{
			$conf['general']['componentsFilePath'] = 'components';
		}
		if(!Util::startsWith($conf['general']['componentsFilePath'], '/'))
		{
			$conf['general']['componentsFilePath'] = $_SERVER['DOCUMENT_ROOT'] . '/' . $conf['general']['componentsFilePath'];
		}
		if(Util::endsWith($conf['general']['componentsFilePath'], '/'))
		{
			$conf['general']['componentsFilePath'] = substr($conf['general']['componentsFilePath'], 0, strlen($conf['general']['componentsFilePath'])-1);
		}
		
		$conf['general']['componentsUrl'] = trim($conf['general']['componentsUrl']);
		if(empty($conf['general']['componentsUrl']))
		{
			if(Util::startsWith($conf['general']['componentsFilePath'], $_SERVER['DOCUMENT_ROOT']))
			{
				$conf['general']['componentsUrl'] = substr($conf['general']['componentsFilePath'], strlen($_SERVER['DOCUMENT_ROOT']));
			}else{
				$conf['general']['componentsUrl'] = '/components';
			}
		}
		if(Util::endsWith($conf['general']['componentsUrl'], '/'))
		{
			$conf['general']['componentsUrl'] = substr($conf['general']['componentsUrl'], 0, strlen($conf['general']['componentsUrl'])-1);
		}
		
		return $conf;
	}
}
?>