<?php
namespace mls\ki\Widgets;

abstract class Form extends Widget
{
	abstract public function handleParams($post = NULL, $get = NULL);
}

?>