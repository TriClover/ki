<?php
namespace mls\ki;

class Ki
{
	public static $siteName = NULL;
	public static function init(string $siteName, bool $sendHeaders = true)
	{
		Ki::$siteName = $siteName;
		\ini_set("default_charset", "UTF-8");
		\mb_internal_encoding("UTF-8");
		\mb_http_output('UTF-8');
		\iconv_set_encoding("internal_encoding", "UTF-8");
		\iconv_set_encoding("output_encoding", "UTF-8");
		if($sendHeaders)
		{
			header('Content-Type: text/html; charset=UTF-8');
		}
		date_default_timezone_set(Config::get()['general']['timezone']);
	}
}
?>