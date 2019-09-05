<?php
namespace mls\ki\Widgets;
use \mls\ki\Database;
use \mls\ki\Log;
use \mls\ki\Util;

/**
 A button to be inserted into a larger form that runs some server side code
*/
class CallbackButton
{
	public $title;
	public $func;
	public $criteria;
	
	/**
	* Construct the button.
	* @param title the text to show on the button
	* @param func the server side function to call when clicked
	* @param criteria function that returns whether the button should be shown for the given input data, or NULL for always
	*/
	public function __construct(string $title, callable $func, callable $criteria = NULL)
	{
		$this->title = $title;
		$this->func = $func;
		$this->criteria = $criteria;
		if($criteria === NULL) $this->criteria = function(...$in){return true;};
	}
}