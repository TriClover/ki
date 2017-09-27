<?php
namespace mls\ki\Security;
use \mls\ki\Database;
use \mls\ki\Log;

/**
* Data about the current HTTP request.
*/
class Request
{
	//important action responses to be shown to the user
	public $systemMessages = array();
	
	//Combined misc information identifying the user agent configuration
	public $fingerprint = '';
	
	//IP Address information
	public $ipAddress = NULL;
	public $ipId = NULL;
	public $ipBlocked = NULL;
	
	function __construct()
	{
		$db = Database::db();
		
		$ip = $_SERVER['REMOTE_ADDR'];
		$this->ipAddress = $ip;
		$this->fingerprint = Request::generateFingerprint();
		
		$ipData = $db->query('SELECT `id`, UNIX_TIMESTAMP(`block_until`) AS block_until FROM ki_IPs WHERE `ip`=INET6_ATON(?) LIMIT 1',
			array($ip), 'checking IP status');
		if($ipData === false) return;
		if(empty($ipData)) //If IP is newly encountered, add it to the list
		{
			$ipIns = $db->query('INSERT INTO `ki_IPs` SET `ip`=INET6_ATON(?)',
				array($ip), 'recording new IP');
			if($ipIns !== 1) return;
			$this->ipId = $db->connection->insert_id;
			$this->ipBlocked = false;
		}else{
			$this->ipId = $ipData[0]['id'];
			$this->ipBlocked = $ipData[0]['block_until'] !== NULL && $ipData[0]['block_until'] >= time();
		}
	}
	
	/*
	* Fingerprint the client machine as well as can be done using only server side code.
	* Sessions require this to be stable over their lifetime;
	* if a user's fingerprint changes, the user gets logged out.
	*/
	public static function generateFingerprint()
	{
		return $_SERVER['HTTP_USER_AGENT'];
	}
}
?>