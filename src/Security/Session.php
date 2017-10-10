<?php
namespace mls\ki\Security;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Log;
use \mls\ki\Util;
/**
* Data about an attached Session.
* It might be an anonymous session or a user login: check User separately.
*/
class Session
{
	//Provided and Database fields
	public $id;
	public $id_hash;
	public $user;
	public $ipId;
	public $fingerprint;
	public $established;
	public $last_active;
	public $remember;
	public $last_id_reissue;
	
	//Derived fields
	public $expiryTime;
	public $expired;
	public $needsIdReissue;
	
	function __construct(string $id,
	                     string $id_hash,
						        $user,
						 int    $ipId,
						 string $fingerprint,
						 int    $established,
						 int    $last_active,
						 bool   $remember,
						 int    $last_id_reissue,
						 int    $expiryTime,
						 bool   $expired,
						 bool   $needsIdReissue)
	{
		$this->id              = $id;
		$this->id_hash         = $id_hash;
		$this->user            = $user;
		$this->ipId            = $ipId;
		$this->fingerprint     = $fingerprint;
		$this->established     = $established;
		$this->last_active     = $last_active;
		$this->remember        = $remember;
		$this->last_id_reissue = $last_id_reissue;
		$this->expiryTime      = $expiryTime;
		$this->expired         = $expired;
		$this->needsIdReissue  = $needsIdReissue;
	}
	
	/**
	* Put this session in the global context and send the cookie.
	* update last-active in database and update ID if necessary
	*/
	public function attach()
	{
		$db = Database::db();
		$now = time();
		
		if($this->needsIdReissue)
		{
			$sidPair = Session::generateSessionId();
			$sid = $sidPair[0];
			$sidHash = $sidPair[1];
			
			$oldHash = $this->id_hash;
			
			$this->id              = $sid;
			$this->id_hash         = $sidHash;
			$this->last_active     = $now;
			$this->last_id_reissue = $now;
			$this->expiryTime      = Session::calculateExpiryTime($now, $this->remember, $now);
			$this->expired         = false;
			$this->needsIdReissue  = false;
			
			$db->query('UPDATE `ki_sessions` SET `id_hash`=?,`last_active`=NOW(),`last_id_reissue`=NOW() WHERE `id_hash`=? LIMIT 1',
				array($this->id_hash, $oldHash), 'Reissuing ID for session and updating last_active');
		}else{
			$this->last_active     = $now;
			$this->expiryTime      = Session::calculateExpiryTime($now, $this->remember, $now);
			$this->expired         = false;
			
			$db->query('UPDATE `ki_sessions` SET `last_active`=NOW() WHERE `id_hash`=? LIMIT 1',
				array($this->id_hash), 'updating last_active for session');
		}
		
		if($this->user !== NULL)
		{
			$db->query('UPDATE `ki_users` SET `last_active`=NOW() WHERE `id`=? LIMIT 1',
				array($this->user), 'updating user last-active');
		}
		
		Authenticator::$session = $this;
		if($this->user !== NULL) Authenticator::$user = User::loadFromId($this->user);
		$this->sendCookie();
	}
	
	/**
	* Send the cookie allowing the user to attach this session in the future.
	*/
	public function sendCookie()
	{
		setcookie('id', $this->id, $this->expiryTime, '/', '', true, true);
	}
	
	/**
	* Promote an anonymous session to a user session with new ID. Updates the DB, returns Session object.
	* Then attach: Put this session in the global context and send the cookie.
	* @param userId the ID of the user to associate with the session
	* @param remember whether the user selected "Remember" when providing creds
	* @return an updated Session object representing the session after doing this change, or false on error.
	*/
	public function promoteAttach(int $userId, bool $remember)
	{
		if($this->user !== NULL)
		{
			Log::warn('Promote called on non-anonymous session');
			return false;
		}
		
		$db = Database::db();
		$sidPair = Session::generateSessionId();
		$sid = $sidPair[0];
		$sidHash = $sidPair[1];
		$now = time();
		
		$oldHash = $this->id_hash;

		$res = $db->query('UPDATE `ki_sessions` SET `user`=?,`id_hash`=?,`established`=FROM_UNIXTIME(?),`last_active`=FROM_UNIXTIME(?),`remember`=?,`last_id_reissue`=FROM_UNIXTIME(?) WHERE id_hash=? LIMIT 1',
			array($userId, $sidHash, $now, $now, $remember, $now, $oldHash),
			'promoting anonymous session to user session');
		if($res === false)
		{
			return false;
		}
		
		$db->query('UPDATE `ki_users` SET `last_active`=NOW() WHERE `id`=? LIMIT 1',
			array($userId), 'updating user last-active');
		
		$this->user            = $userId;
		$this->id              = $sid;
		$this->id_hash         = $sidHash;
		$this->established     = $now;
		$this->last_active     = $now;
		$this->remember        = $remember;
		$this->last_id_reissue = $now;
		$this->expiryTime      = Session::calculateExpiryTime($now, $remember, $now);
		$this->expired         = false;
		$this->needsIdReissue  = false;
		
		Authenticator::$session = $this;
		Authenticator::$user = User::loadFromId($userId);
		$this->sendCookie();
		return $this;
	}
	
	/**
	* Send deletion cookie
	*/
	public static function deleteCookie()
	{
		setcookie('id', '', time()-86400, '/', '', true, true);
	}
	
	/**
	* Attempt to load a session based on given (possibly suspect) Session ID
	* @param sid Session ID to try
	* @param requestContext a Request object representing the request within which to load the session
	* @return a new Session object on success; NULL when $sid not found, FALSE on error (ie subsystem error, or if the $sid was good but the session itself was bad e.g. expired, or belonging to a locked out user etc)
	*/
	public static function load($sid, $requestContext)
	{
		$db = Database::db();
		if($requestContext->ipId === NULL) return false;
		
		$sidHash = Authenticator::pHash($sid);
		$sessionData = $db->query('SELECT `id_hash`,`user`,UNIX_TIMESTAMP(`established`) AS established,UNIX_TIMESTAMP(`last_active`) AS last_active,`remember`,UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? AND `ip`=? AND `fingerprint`=? LIMIT 1',
			array($sidHash, $requestContext->ipId, $requestContext->fingerprint), 'looking up session requested by user');
		if($sessionData === false) return false;
		if(empty($sessionData)) return NULL;
		$sessionData = $sessionData[0];
		$expiry = Session::calculateExpiryTime($sessionData['established'], $sessionData['remember'], $sessionData['last_active']);
		$sidExpired = time() >= $expiry;
		$deleteThisSession = false;
		if($sessionData['user'] === NULL)
		{
			$sidExpired = false; //anonymous sessions don't expire
		}else{
			//check if anything about the user prevents the session from being good
			$user = User::loadFromId($sessionData['user']);
			if(!$user->enabled || $user->lockedOut) $deleteThisSession = true;
		}
		if($sidExpired) $deleteThisSession = true;
		if($deleteThisSession)
		{
			Session::deleteSession($sidHash);
			return false;
		}
		$reissueTimestamp = Session::calculateReissueTime($sessionData['last_id_reissue']);
		$needsReissue = time() >= $reissueTimestamp;
		
		$session =  new Session($sid,
		                        $sidHash,
					     	    $sessionData['user'],
						        $requestContext->ipId,
						        $requestContext->fingerprint,
						        $sessionData['established'],
						        $sessionData['last_active'],
						        $sessionData['remember'],
						        $sessionData['last_id_reissue'],
								$expiry,
						        $sidExpired,
						        $needsReissue);
		return $session;
	}
	
	/**
	* Make a new session: save it in the database and return the Session object.
	* Caller must handle sending the cookie, if desired.
	* @param userId The user this session is for. If NULL, make an anonymous session.
	* @param requestContext the request in which this session is being created. Subsequent requests that want to attach to this session must have certain things in common with the given "original" session.
	* @return a Session object representing the session created, or false on error
	*/
	public static function create($userId, bool $remember, Request $requestContext)
	{
		$db = Database::db();
		$sidPair = Session::generateSessionId();
		$sid = $sidPair[0];
		$sidHash = $sidPair[1];
		$now = time();
		$expiry = Session::calculateExpiryTime($now, $remember, $now);
		$session = new Session($sid,$sidHash,$userId,$requestContext->ipId,$requestContext->fingerprint,$now,$now,$remember,$now,$expiry,false,false);
		$res = $db->query('INSERT INTO `ki_sessions` SET `id_hash`=?,`user`=?,`ip`=?,`fingerprint`=?,`established`=FROM_UNIXTIME(?),`last_active`=FROM_UNIXTIME(?),`remember`=?,`last_id_reissue`=FROM_UNIXTIME(?)',
			array($sidHash,$userId,$requestContext->ipId,$requestContext->fingerprint,$now,$now,$remember,$now),
			'creating session');
		if($res === false)
			return false;
		else
			return $session;
	}
	
	/**
	* Calculates when a session will expire, assuming no more activity.
	* Takes the sooner of the idle timeout and absolute timeout.
	*
	* @param established timestamp for beginning of session
	* @param remember whether the session has Remember enabled
	* @param last_active timestamp from which to calculate idle timeout. Leave NULL to use the current time; eg. when starting or refreshing a session. Specify a time to check whether an old session has expired or not without refreshing it.
	* @return timestamp for when the session should expire
	*/
	public static function calculateExpiryTime(int $established, bool $remember, int $last_active = NULL)
	{
		if($last_active === NULL) $last_active = time();
		$config = Config::get()['sessions'];
		$idle_seconds = $config['remembered_timeout_idle_minutes'] * 60;
		$absolute_seconds = $config['remembered_timeout_absolute_hours'] * 60 * 60;
		if(!$remember)
		{
			$idle_seconds = $config['temp_timeout_idle_minutes'] * 60;
			$absolute_seconds = $config['temp_timeout_absolute_hours'] * 60 * 60;
		}
		$idle = $last_active + $idle_seconds;
		$absolute = $established + $absolute_seconds;
		return min($idle, $absolute);
	}
	
	/**
	* Calculates when a session will be due to have its ID reissued
	* @param lastReissue timestamp for when it was last reissued
	* @return timestamp for when to reissue the ID
	*/
	public static function calculateReissueTime($lastReissue)
	{
		$reissueDuration = Config::get()['sessions']['reissue_minutes'] * 60;
		return $lastReissue + $reissueDuration;
	}
	
	/**
	* Generates a new session ID; guaranteed to be unique
	* because it checks against the database.
	* Does not write anything to the database.
	*
	* @return an array($sessionID, $hashedSessionID)
	*/
	public static function generateSessionId()
	{
		$db = Database::db();
		$sid = '';
		$sid_hash = '';
		do{
			$sid = Util::random_str(32);
			$sid_hash = Authenticator::pHash($sid);
			$dups = $db->query('SELECT `id_hash` FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
				array($sid_hash), 'checking for duplicate session ID hash');
		}while(!empty($dups));
		return array($sid, $sid_hash);
	}
	
	/**
	* Deletes a session from the DB
	* @param sidHash the sid_hash to look for
	*/
	public static function deleteSession(string $sidHash)
	{
		$db = Database::db();
		$db->query('DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
			array($sidHash), 'deleting session');
	}
}
?>