<?php
namespace mls\ki\Widgets;

abstract class Widget
{
	protected static function filterStyles(array $inputStyles, array $allowedStyles)
	{
		$out = '';
		foreach($inputStyles as $name => $value)
		{
			if(in_array($name, $allowedStyles)) $out .= $name . ':' . $value . ';';
		}
		return $out;
	}
}

?>