<?php
namespace mls\ki;

class Config
{
	protected static $conf = NULL;
	protected static $path = NULL;
	
	public static function get()
	{
		if(Config::$path === NULL)
		{
			Config::$path = $_SERVER['DOCUMENT_ROOT'] . '/../config/' . Ki::$siteName . '.json';
		}
		if(Config::$conf === NULL)
		{
			Config::$conf = array();
			$fileContents = file_get_contents(Config::$path);
			Config::$conf = json_decode($fileContents, true, 512, JSON_BIGINT_AS_STRING|JSON_OBJECT_AS_ARRAY);
			Config::$conf = Config::processDefaults(Config::$conf);
		}
		return Config::$conf;
	}
	
	public static function set(array $keys, string $value)
	{
		if(Config::$conf === NULL) return false;
		
		$references = [&Config::$conf];
		for($i=0; $i<count($keys); ++$i)
		{
			$key = $keys[$i];
			if(!is_array($references[$i]) || !isset($references[$i][$key])) return false;
			$references[$i+1] =& $references[$i][$key];
		}
		$references[count($references)-1] = $value;
		$reRes = Config::rewrite();
		
		if($reRes === false) return false;
		return true;
	}
	
	public static function rewrite(array $newConf = NULL)
	{
		if($newConf === NULL)
		{
			$newConf = Config::$conf;
		}else{
			Config::$conf = $newConf;
		}
		$json = json_encode($newConf, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
		$bytes = file_put_contents(Config::$path, $json);
		
		if($bytes === false) return false;
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