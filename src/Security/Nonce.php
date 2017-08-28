<?php
namespace mls\ki\Security;

/**
* Represents a nonce, a one-use key stored in the database to create a
* one to one correspondence between pairs of requests.
* Used for such purposes as verifying email account control and
* protecting forms from CSRF and double submit.
*/
class Nonce
{
	public $nonceValue;          //The actual nonce contained in this object
	public $nonceHash;           //The hash of the nonce, which gets stored in the database
	public $purpose;             //The feature that uses the nonce
	public $user;                //User associated with the nonce
	public $sessionHash;         //Session ID associated with the nonce (as a hash since that's what is stored in the DB)
	public $require_user;        //If true, and $user is not null, nonce is only valid on requests where $user is logged in. 
	public $require_sessionHash; //If true, and $sessionHash is not null, nonce is only valid on requests in the session corresponding to $sessionHash
	public $created_timestamp;   //When the nonce was added to the database; only needed for retrieved nonces, not ones being created in the current request.
	
	function __construct(string $nonceValue,
	                     string $nonceHash,
	                     string $purpose,
	                     int    $user = NULL,
	                     string $sessionHash = NULL,
	                     bool   $require_user = true,
	                     bool   $require_sessionHash = true)
	{
		$this->nonceValue          = $nonceValue;
		$this->nonceHash           = $nonceHash;
		$this->purpose             = $purpose;
		$this->user                = $user;
		$this->sessionHash         = $sessionHash;
		$this->require_user        = $require_user;
		$this->require_sessionHash = $require_sessionHash;
	}
	
}

?>