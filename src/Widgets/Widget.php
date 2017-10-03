<?php
namespace mls\ki\Widgets;
use \mls\ki\Log;

abstract class Widget
{
	protected static function filterStyles(array $inputStyles, array $allowedStyles, array $defaultStyles = array())
	{
		foreach($defaultStyles as $name => $value)
		{
			if(!in_array($name, $allowedStyles))
			{
				Log::warn('Non-allowed style "' . $name . '" specified in default list for widget');
			}else{
				if(!isset($inputStyles[$name])) $inputStyles[$name] = $value;
			}
		}
		
		$out = '';
		foreach($inputStyles as $name => $value)
		{
			if(in_array($name, $allowedStyles)) $out .= $name . ':' . $value . ';';
		}
		return $out;
	}
	
	abstract public function getHTML();
}

?>