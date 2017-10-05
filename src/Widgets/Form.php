<?php
namespace mls\ki\Widgets;
use \mls\ki\Log;

/**
* Represents a UI element that can do an HTML form submit back to the server,
* and so needs a way to both dynamically generate its HTML and
* handle the submitted parameters from its HTML forms.
*/
abstract class Form extends Widget
{
	/**
	* Indicates that the HTTP params (get/post) have been handled.
	* This prevents doing the DB operations multiple times.
	*/
	protected $handledParams = false;   
	/** HTTP POST parameters */
	protected $post;
	/** HTTP GET parameters */
	protected $get;
	
	/**
	* Handle user parameters for this form (user submitted POST/GET data) and
	* perform whatever processing, database access, etc is needed.
	* Also keep track of the fact that this was done, so that
	* proper action can be taken if the form is used in an improper way.
	* This is the method external code will want to call to kickoff the processing.
	* @param post Injected POSTdata. If NULL, uses the real $_POST
	* @param get Injected GETdata. If NULL, uses the real $_GET
	* @return whatever is returned from the implementation's handleParamsInternal()
	*/
	final public function handleParams($post = NULL, $get = NULL)
	{
		$this->post = ($post === NULL) ? $_POST : $post;
		$this->get  = ($get  === NULL) ? $_GET  : $get;
		
		if($this->handledParams)
		{
			Log::error('Tried to handle params for the same Form twice in one page load');
			return false;
		}
		$this->handledParams = true;
		if($this->printed)
		{
			Log::error('Form handling params after generating HTML, but getHtml assumes params have already been handled and probably showed wrong information');
		}
		return $this->handleParamsInternal();
	}
	
	/**
	* Get the HTML of this form. Also keep track of the fact that this was done, so that
	* proper action can be taken if the form is used in an improper way.
	* This is the method external code will want to call to get the HTML.
	* @return the HTML for this form
	*/
	final public function getHTML()
	{
		if(!$this->handledParams)
		{
			Log::warn('Form generating HTML without having handled params. This may cause usability issues.');
		}
		return parent::getHTML();
	}
	
	/**
	* Internal method that actually performs the processing for the submitted form. It should only be
	* called by handleParams(). This is the method that must be overridden by classes that inherit
	* from Form, to provide their unique processing and data manipulation.
	* Implementations of handleParamsInternal() should focus mostly on writing data
	* rather than reading, and should certainly not generate any HTML. This way you can avoid
	* relying on data too early when other things may not be done operating on it.
	* @return Whatever you want. This value will be returned to the caller of handleParams()
	*/
	abstract protected function handleParamsInternal();
}

?>