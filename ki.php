<?php
namespace ki;

foreach(glob(__DIR__ . "/modules/*/*.php") as $filename)
{
    require_once($filename);
}

function init()
{
	header(httpHeaders());
	date_default_timezone_set(config()['general']['timezone']);
	database\connect_all();
}
?>