<?php
namespace mls\ki;
use \mls\ki\Config;

class MarkupGenerator
{
	public static function pageHeader($headContent = '')
	{
		$config = Config::get();
		$comp = $config['general']['staticDir'];
		$env = $config['general']['environment'];
		$indicator = $config['general']['showEnvironment'] && !empty($config['general']['environment']);
		$mt_jquery_js    = filemtime($comp . '/jquery.min.js');
		$mt_jqueryui_js  = filemtime($comp . '/jquery-ui/jquery-ui.min.js');
		$mt_jqueryui_css = filemtime($comp . '/jquery-ui/jquery-ui.min.css');
		$mt_webshim_js   = filemtime($comp . '/webshim/polyfiller.js');
		$mt_ki_css       = filemtime($comp . '/ki/ki.css');
		$mt_ki_js        = filemtime($comp . '/ki/ki.js');
		
		$title = $config['general']['sitename'] . ' - ' . htmlspecialchars(pathinfo($_SERVER['PHP_SELF'])['filename']);
		$base = $config['general']['staticUrl'];
		$out = <<<HTMLHEAD
<!DOCTYPE html>
<html>
 <head itemscope>
  <meta charset="utf-8"/>
  <meta http-equiv="X-UA-Compatible" content="IE=edge"/>
  <link rel="shortcut icon" href="$base/favicon.ico"/>
  <script src="$base/jquery.min.js?ver=$mt_jquery_js"></script>
  <script src="$base/jquery-ui/jquery-ui.min.js?ver=$mt_jqueryui_js"></script>
  <link rel="stylesheet" href="$base/jquery-ui/jquery-ui.min.css?ver=$mt_jqueryui_css"/>
  <script src="$base/webshim/polyfiller.js?ver=$mt_webshim_js"></script>
  <script>webshims.polyfill('forms forms-ext details geolocation');</script>
  <link rel="stylesheet" href="$base/ki/ki.css?ver=$mt_ki_css"/>
  <script src="$base/ki/ki.js?ver=$mt_ki_js"></script>
  <title>$title</title>
  <meta itemprop="environment" content="$env"/>

HTMLHEAD;
		$out .= $headContent;
		$out .= " </head>\n <body>";
		
		if($indicator)
		{
			$out .= '<div id="ki_environmentIndicator">Environment: '
				. $env . ' &nbsp; <a id="ki_envind_close" href="javascript:kiEnvIndClose();">✘</a></div>';
		}
		
		return $out;
	}

	public static function pageFooter()
	{
		return "\n </body>\n</html>";
	}
}
?>