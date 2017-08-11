<?php
namespace mls\ki\Security;

/**
* Data about the current logged in user.
* If no user logged in, User will be NULL.
* If NULL, there may or may not still be an anonymous session; check Session separately.
*/
class User
{
	public $id;
	public $username;
	public $email;
	public $email_verified;
	public $password_hash;
	public $enabled;
	public $last_active;
	
	public $permissions = array();
	
	function __construct($id, $username, $email, $email_verified, $password_hash, $enabled, $last_active)
	{
		$this->id             = $id;
		$this->username       = $username;
		$this->email          = $email;
		$this->email_verified = $email_verified;
		$this->password_hash  = $password_hash;
		$this->enabled        = $enabled;
		$this->last_active    = $last_active;
	}
}
?>