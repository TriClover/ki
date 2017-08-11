<?php
namespace mls\ki\Security;

/**
* Data about the current session.
* It might be an anonymous session or a user login: check User separately.
*/
class Session
{
	public $id;
	public $id_hash;
	public $ip;
	public $fingerprint;
	public $established;
	public $last_active;
	public $remember;
	public $last_id_reissue;
	
	function __construct($id, $id_hash, $ip, $fingerprint, $established, $last_active, $remember, $last_id_reissue)
	{
		$this->id              = $id;
		$this->id_hash         = $id_hash;
		$this->ip              = $ip;
		$this->fingerprint     = $fingerprint;
		$this->established     = $established;
		$this->last_active     = $last_active;
		$this->remember        = $remember;
		$this->last_id_reissue = $last_id_reissue;
	}
}
?>