<?php
namespace ki;

function pageHeader($headContent = '')
{
	$out = file_get_contents(__DIR__ . '/../../snippets/header.html');
	$comp = config()['general']['componentsFilePath'];
	$kicomp = $comp . '/ki/setup/static';
	$mt_jquery_js    = filemtime($comp . '/jquery/jquery.min.js');
	$mt_jqueryui_js  = filemtime($comp . '/jquery-ui/jquery-ui.min.js');
	$mt_jqueryui_css = filemtime($comp . '/jquery-ui/themes/base/jquery-ui.min.css');
	$mt_webshim_js   = filemtime($kicomp . '/webshim/polyfiller.js');
	$mt_ki_css       = filemtime($kicomp . '/ki.css');
	$mt_ki_js        = filemtime($kicomp . '/ki.js');
	
	$title = config()['general']['sitename'] . ' - ' . htmlspecialchars(pathinfo($_SERVER['PHP_SELF'])['filename']);
	$base = config()['general']['componentsUrl'];
	$out .= <<<HTMLHEAD
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

function pageFooter()
{
	return "\n </body>\n</html>";
}

function httpHeaders()
{
	return 'Content-Type: text/html; charset=UTF-8';
}
?>