<?php
namespace mls\ki\Widgets;
use \mls\ki\Log;

/**
* Represents a UI element whose HTML is dynamically generated in some way.
*/
abstract class Widget
{
	/** The HTML has been generated. This helps detect improper usage */
	protected $printed = false;
	
	/**
	* This is a helper for your HTML generation function that resolves CSS from associative arrays into
	* ready-to-use strings, filtering for whitelisted properties and default values.
	* Tip: This can be called multiple times with different allowedStyles to make separate style strings
	* for use in different tags of your widget.
	* @param inputStyles Associative array of styles recieved from the code instantiating your class.
	* @param allowedStyles The names of CSS properties your code wants to use
	* @param defaultStyles Associative array of styles to be returned for properties not specified in inputStyles
	* @return a string ready to insert into the "style" propery of an HTML tag.
	*/
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
	
	/**
	* Get the HTML of this widget. Also keep track of the fact that this was done, so that
	* proper action can be taken if the widget is used in an improper way.
	* This is the method external code will want to call to get the HTML.
	* @return the HTML for this widget
	*/
	public function getHTML()
	{
		if($this->printed)
		{
			Log::warn('Generated HTML for the same DataTable twice in one page load. This is bad for performance.');
		}
		$this->printed = true;
		return $this->getHTMLInternal();
	}
	
	/**
	* Internal method that actually generates the HTML for the widget. It should only be
	* called by getHTML(). This is the method that must be overridden by classes that inherit
	* from Widget, to provide their unique HTML generation.
	* No other action besides HTML generation needs to be taken by implementations of getHTMLInternal().
	* @return the HTML for this widget
	*/
	abstract protected function getHTMLInternal();
}

?>