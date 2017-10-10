<?php
namespace mls\ki;

class Config
{
	protected static $conf = NULL;
	protected static $iniFile = NULL;
	protected static $path = NULL;
	
	public static function get()
	{
		if(Config::$path === NULL)
		{
			Config::$path = $_SERVER['DOCUMENT_ROOT'] . '/../config/ki.ini';
		}
		if(Config::$conf === NULL)
		{
			Config::$conf = array();
			Config::$iniFile = file_get_contents(Config::$path);
			Config::$conf = parse_ini_string(Config::$iniFile, true, INI_SCANNER_RAW);
			Config::$conf = Config::processDefaults(Config::$conf);
		}
		return Config::$conf;
	}
	
	public static function set(string $section, string $key, string $value)
	{
		if(Config::$conf === NULL
			|| empty(Config::$conf)
			|| !isset(Config::$conf[$section])
			|| empty(Config::$conf[$section])
			|| !isset(Config::$conf[$section][$key]))
		{
			return false;
		}
		//(the section header and everything before it + all lines in the same section (key value pairs, comments, or blank lines) up to the one being changed) the line being changed (everything after the line being changed)
		$pattern = '/([\s\S]*\[' . $section . '\][\r\n]+(?:(?:\w+\h*=\h*\S*|\;[\S\h]*)[\r\n]+)*)' . $key . '\h*=[\S\h]*([\s\S]*)/i';
		$replacement = '$1' . $key . '=' . $value . '$2';
		Config::$iniFile = preg_replace($pattern, $replacement, Config::$iniFile);
		file_put_contents(Config::$path, Config::$iniFile);
		Config::$conf = NULL;
		Config::get();
		return true;
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