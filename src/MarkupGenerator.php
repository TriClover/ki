<?php
namespace mls\ki;
use \mls\ki\Config;
use \mls\ki\Log;

class MarkupGenerator
{
	protected static $clientSideIncludes = [];
	
	public static function pageHeader($headContent = '')
	{
		$config    = Config::get();
		$comp      = $config['general']['staticDir'];
		$env       = $config['general']['environment'];
		$indicator = $config['general']['showEnvironment'] && !empty($config['general']['environment']);
		$base      = $config['general']['staticUrl'];
		$title     = $config['general']['sitename'] . ' - ' . htmlspecialchars(pathinfo($_SERVER['PHP_SELF'])['filename']);
		$files     = [
			'/lib/jquery/jquery.min.js',
		    '/lib/jquery-ui/jquery-ui.min.js',
			'/lib/jquery-ui/themes/base/jquery-ui.min.css',
			'/lib/webshim/polyfiller.js',
			'/lib/ki/ki.css',
			'/lib/ki/ki.js',
			'/lib/ki/ki_querybuilder.js',
			'/lib/multiselect/jquery.multiselect.css',
			'/lib/multiselect/src/jquery.multiselect.js'
		];
		$files = array_merge($files, static::$clientSideIncludes);
		$includes = '';
		foreach($files as $file)
		{
			$mtime = filemtime($comp . $file);
			$dotPosition = strrpos($file, '.');
			if($dotPosition === false)
			{
				Log::error("Couldn't determine extension of header include file: " . $file);
				continue;
			}
			$extension = substr($file, $dotPosition+1);
			$url = $base . $file . '?ver=' . $mtime;
			if($extension == 'css')
			{
				$includes .= '<link rel="stylesheet" href="' . $url . '"/>';
			}else{
				$includes .= '<script src="' . $url . '"></script>';
			}
		}

		$out = <<<HTMLHEAD
<!DOCTYPE html>
<html>
 <head itemscope>
  <meta charset="utf-8"/>
  <meta http-equiv="X-UA-Compatible" content="IE=edge"/>
  <link rel="shortcut icon" href="$base/favicon.ico"/>
  $includes
  <script>webshims.polyfill('forms forms-ext details geolocation');</script>
  <title>$title</title>
  <meta itemprop="environment" content="$env"/>

HTMLHEAD;
		$out .= $headContent;
		$out .= " </head>\n <body>";
		
		if($indicator)
		{
			$out .= '<div id="ki_environmentIndicator">Environment: '
				. $env . ' &nbsp; <a id="ki_envind_close" href="javascript:kiEnvIndClose();">✘</a></div>'
				. "\n";
		}
		
		return $out;
	}

	public static function pageFooter()
	{
		return "\n </body>\n</html>";
	}
	
	/**
	* @param file a resource file (css/js) (or array of them) to be included when the page head is generated.
	*/
	public static function registerInclude($file)
	{
		if(is_array($file))
		{
			static::$clientSideIncludes = array_merge(static::$clientSideIncludes, $file);
		}else{
			static::$clientSideIncludes[] = $file;
		}
	}
}
?>