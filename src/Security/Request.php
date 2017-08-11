<?php
namespace mls\ki\Security;
/**
* Data about the current HTTP request.
*/
class Request
{
	//true when the user has submitted their correct password with this request.
	//Useful for security sensitive actions requiring re-auth like changing the password
	public $verifiedPassword = false;
	//true when the user has clicked a valid nonce link from their email.
	//Useful for very security sensitive actions requiring email verification
	public $verifiedEmail = false;
	//true if the user submitted a valid CSRF token via POST with this request.
	//the token is removed from the database when the check is done.
	//this can be used to ensure that a protected action corresponds to a correct pageload from a valid user
	//and that only ONE such action can result from each page load
	public $validCsrf = false;
	//important action responses to be shown to the user
	public $systemMessages = array();
	//Combined misc information identifying the user agent configuration
	public $fingerprint = '';
	public $ip = NULL;
}
?>