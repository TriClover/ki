<?php
namespace mls\ki;
use \mls\ki\Config;
use \mls\ki\Log;

class MarkupGenerator
{
	public static function pageHeader($headContent = '')
	{
		$config    = Config::get();
		$comp      = $config['general']['staticDir'];
		$env       = $config['general']['environment'];
		$indicator = $config['general']['showEnvironment'] && !empty($config['general']['environment']);
		$base      = $config['general']['staticUrl'];
		$title     = $config['general']['sitename'] . ' - ' . htmlspecialchars(pathinfo($_SERVER['PHP_SELF'])['filename']);
		$files     = [
			'jquery/jquery.min.js',
		    'jquery-ui/jquery-ui.min.js',
			'jquery-ui/themes/base/jquery-ui.min.css',
			'webshim/polyfiller.js',
			'ki/ki.css',
			'ki/ki.js',
			'ki/ki_querybuilder.js',
			'multiselect/css/jquery.multiselect.css',
			'multiselect/src/jquery.multiselect.js'
		];
		$includes = '';
		foreach($files as $file)
		{
			$mtime = filemtime($comp . '/lib/' . $file);
			$dotPosition = strrpos($file, '.');
			if($dotPosition === false)
			{
				Log::warn("Couldn't determine extension of header include file: " . $file);
				continue;
			}
			$extension = substr($file, $dotPosition+1);
			$url = $base . '/lib/' . $file . '?ver=' . $mtime;
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
}
?>