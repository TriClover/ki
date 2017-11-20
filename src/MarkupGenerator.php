<?php
namespace mls\ki;
use \mls\ki;
use \mls\ki\Config;

class MarkupGenerator
{
	public static function pageHeader($headContent = '')
	{
		$comp = Config::get()['general']['componentsFilePath'];
		$kicomp = $comp . '/ki/static';
		$mt_jquery_js    = filemtime($comp . '/jquery/jquery.min.js');
		$mt_jqueryui_js  = filemtime($comp . '/jquery-ui/jquery-ui.min.js');
		$mt_jqueryui_css = filemtime($comp . '/jquery-ui/themes/base/jquery-ui.min.css');
		$mt_webshim_js   = filemtime($kicomp . '/webshim/polyfiller.js');
		$mt_ki_css       = filemtime($kicomp . '/ki.css');
		$mt_ki_js        = filemtime($kicomp . '/ki.js');
		
		$title = Config::get()['general']['sitename'] . ' - ' . htmlspecialchars(pathinfo($_SERVER['PHP_SELF'])['filename']);
		$base = Config::get()['general']['componentsUrl'];
		$out = <<<HTMLHEAD
<!DOCTYPE html>
<html>
 <head>
  <meta charset="utf-8"/>
  <meta http-equiv="X-UA-Compatible" content="IE=edge"/>
  <link rel="shortcut icon" href="/static/favicon.ico"/>
  <script src="$base/jquery/jquery.min.js?ver=$mt_jquery_js"></script>
  <script src="$base/jquery-ui/jquery-ui.min.js?ver=$mt_jqueryui_js"></script>
  <link rel="stylesheet" href="$base/jquery-ui/themes/base/jquery-ui.min.css?ver=$mt_jqueryui_css"/>
  <script src="$base/ki/setup/static/webshim/polyfiller.js?ver=$mt_webshim_js"></script>
  <script>webshims.polyfill('forms forms-ext details geolocation');</script>
  <link rel="stylesheet" href="$base/ki/setup/static/ki.css?ver=$mt_ki_css"/>
  <script src="$base/ki/setup/static/ki.js?ver=$mt_ki_js"></script>
  <title>$title</title>
HTMLHEAD;
		$out .= $headContent;
		$out .= " </head>\n <body>";
		return $out;
	}

	public static function pageFooter()
	{
		return "\n </body>\n</html>";
	}
}
?>