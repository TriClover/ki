<?php
namespace mls\ki\Setup;
use \mls\ki\Database;
use \mls\ki\Security\Request;
use \mls\ki\Widgets\Form;

/**
* Extend this class to create a module for the site setup process.
* Pass the fully qualified class name of your implementation
* into the Setup constructor to activate it.
*/
abstract class SetupModule extends Form
{
	const SUCCESS = 1;
	const FAILURE = 2; //Hard failure halts processing of any further steps
	const WARNING = 3; //Warning alerts user of the problem but allows setup to continue past this step

	//reference back to the Setup using this module, if we need to get or set the Database/Request objects
	protected $setup = NULL;
	
	function __construct(Setup &$setup)
	{
		$this->setup =& $setup;
	}
	
	/**
	* @return The name of the setup-step represented by this class, which will be shown to the user.
	*/
	abstract public function getFriendlyName();
	
	/*The following functions come from Form and can't be redeclared here, but
	notice the stricter expectations for them in implementations of SetupModule.*/
	
	/**
	* Do the setup processing, data gathering and so on.
	* @return one of the response code constants of this class.
	*/
	//abstract protected function handleParamsInternal();
	
	/**
	* Extra HTML to show the user for this step. Forms to take user input go here, as does
	* an explanation of the problem if you returned a non-success code from handleParamsInternal()
	* @return HTML shown to the user in the extra/details field for this module
	*/
	//abstract protected function getHTMLInternal();

}

?>