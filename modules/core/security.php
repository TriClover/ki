<?php
namespace ki\security;
use function \ki\database\query;
use function \ki\util\random_str;
use \ki\widgets\DataTable;
use \ki\widgets\DataTableField;
use \ki\widgets\DataTableEventCallbacks;

/**
* Checks session status and fills the user/session globals with the info that pages need
* to check authorization and/or track the user.
* First, see if they provided a valid ID for an existing session.
* If parameters are passed, process a user login attempt with them.
* If the user ends up not logged in, give them a new anonymous session.
* Also check session expiry, SID reissue threshold, account lockout, etc.
*
* @param logout if true, terminate any existing session and give them a new anonymous session
* @return string on failed login attempt using username/password, false on errors so serious even an anonymous session couldn't be attached, true otherwise.
*/
function checkLogin($username=NULL, $password=NULL, bool $remember=true, bool $logout=false)
{
	$db = \ki\db();
	$ip = $_SERVER['REMOTE_ADDR'];
	$fingerprint = generateFingerprint();
	$pwWindowBegin = time() - (\ki\config()['limits']['passwordAttemptWindow_minutes']*60);
	$pwMax = \ki\config()['limits']['maxPasswordAttempts'];
	$accountsWindowBegin = time() - (\ki\config()['limits']['accountAttemptWindow_minutes']*60);
	$accMax = \ki\config()['limits']['maxAccountAttempts'];
	$tooManyLoginsMsg = 'Too many login attempts.';
	
	$db->begin_transaction();
	$ret = true;
	$cookieParams = NULL;
	$loggedABadAttempt = false;
	
	//Check current standing of requestor's IP
	$ipData = query($db, 'SELECT `id`,INET6_NTOA(`ip`) AS ip, UNIX_TIMESTAMP(`block_until`) AS block_until FROM ki_IPs WHERE `ip`=INET6_ATON(?) LIMIT 1',
		array($ip), 'checking IP status');
	if($ipData === false) {$db->commit(); $db->autocommit(true); return false;}
	if(empty($ipData)) //If IP is newly encountered, add it to the list
	{
		$ipIns = query($db, 'INSERT INTO `ki_IPs` SET `ip`=INET6_ATON(?)', array($ip), 'recording new IP');
		if($ipIns !== 1) {$db->commit(); $db->autocommit(true); return false;}
		$ipData = array('id' => $db->insert_id, 'ip' => $ip, 'block_until' => NULL);
	}else{
		$ipData = $ipData[0];
	}
	$ipBlocked = $ipData['block_until'] !== NULL && $ipData['block_until'] > time();

	//If a session ID was provided, check it
	if(isset($_COOKIE['id']) && !empty($_COOKIE['id']))
	{
		if(!$ipBlocked) //skip trying to lookup the session if IP is blocked
		{
			$sid = $_COOKIE['id'];
			$sidHash = pHash($sid);
			$session = query($db, 'SELECT `id_hash`,`user`,UNIX_TIMESTAMP(`established`) AS established,UNIX_TIMESTAMP(`last_active`) AS last_active,`remember`,UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? AND `ip`=? AND `fingerprint`=? LIMIT 1',
				array($sidHash, $ipData['id'], $fingerprint), 'looking up session requested by user');
			//echo(\ki\util\toString(array($sid, $sidHash, $ipData['id'], $fingerprint)));
			if(is_array($session) && !empty($session)) //If SID is valid
			{
				$session = $session[0];
				//if idle/abs timeout reached
				if(time() >= sessionExpiry($session['established'], $session['remember'], $session['last_active']))
				{
					query($db, 'DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
						array($sidHash), 'cleaning up expired session');
				}else{
					if($session['user'] !== NULL)
					{
						query($db, 'UPDATE `ki_users` SET `last_active`=NOW() WHERE `id`=?',
							array($session['user']), 'udpating user last active');
						$user = query($db, 'SELECT `username`, `email`, `email_verified`, `password_hash`, `enabled`, UNIX_TIMESTAMP(`last_active`) AS last_active FROM `ki_users` WHERE `id`=? LIMIT 1',
							array($session['user']), 'loading user for session');
						if(is_array($user) && !empty($user))
						{
							$user = $user[0];
							User::$current = new User($session['user'], $user['username'], $user['email'], $user['email_verified'], $user['password_hash'], $user['enabled'], $user['last_active']);
						}
					}
					if(User::$current !== NULL && !User::$current->enabled)
					{
						User::$current = NULL;
						query($db, 'DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
							array($sidHash), 'deleting session of disabled user');
					}else{
						$reissueSeconds = \ki\config()['sessions']['reissue_minutes']*60;
						$reissueTime = $session['last_id_reissue'] + $reissueSeconds;
						if($reissueTime <= time()) //if time for reissuing session id, do that while updating last active
						{
							$sidPair = generateSessionId();
							$sid = $sidPair[0];
							$sidHash = $sidPair[1];
							query($db, 'UPDATE `ki_sessions` SET `id_hash`=?,`last_active`=NOW(),`last_id_reissue`=NOW() WHERE `id_hash`=? LIMIT 1',
								array($sidHash, $session['id_hash']), 'reissuing session ID and updating session last active');
							//todo: execute hooks for session id change
						}else{
							query($db, 'UPDATE `ki_sessions` SET `last_active`=NOW() WHERE `id_hash`=? LIMIT 1',
								array($session['id_hash']), 'updating session last active');
						}
						$session = query($db, 'SELECT `id_hash`,`user`,UNIX_TIMESTAMP(`established`) AS established,UNIX_TIMESTAMP(`last_active`) AS last_active,`remember`,UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
							array($sidHash), 'reloading session data after updating it');
						if($session === false)
						{
							User::$current = NULL;
						}else{
							$session = $session[0];
							Session::$current = new Session($sid, $sidHash, $ip, $fingerprint, $session['established'], $session['last_active'], $session['remember'], $session['last_id_reissue']);
							$cookieParams = array('id', $sid, sessionExpiry($session['established'], $session['remember'], $session['last_active']), '/', '', true, true);
						}
					}
				}
			}else{
				//if the given sid was confirmed invalid (ie not returned as valid and there was no DB error)
				if($session !== false)
				{
					query($db, 'INSERT INTO ki_failedSessions SET `inputSessionId`=?,`ip`=?,`when`=NOW()',
						array($sid, $ipData['id']), 'logging invalid session attach attempt');
					$loggedABadAttempt = true;
				}
			}
		}
	}
	
	//if user/pass provided for login attempt, and GIVEN user not already loaded via recognized session
	if($username !== NULL && $password !== NULL && (User::$current === NULL || User::$current->username != $username))
	{
		if(User::$current !== NULL) //different user loaded
		{
			query($db, 'DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
				array(Session::$current->id_hash), 'terminating session being replaced');
			Session::$current = NULL;
			User::$current = NULL;
		}
		
		$user = query($db, 'SELECT `id`, `email`, `email_verified`, `password_hash`, `enabled`, UNIX_TIMESTAMP(`last_active`) AS last_active FROM `ki_users` WHERE `username`=? LIMIT 1',
			array($username), 'getting user info for login attempt');
		$badLogins = query($db, 'SELECT COUNT(*) AS total FROM `ki_failedLogins` WHERE `inputUsername`=? AND `when`>FROM_UNIXTIME(?)',
			array($username, $pwWindowBegin), 'checking total bad logins for account');
		$lockedOut = false;
		if($badLogins !== false && !empty($badLogins) && $badLogins[0]['total'] > $pwMax)
		{
			$lockedOut = true;
		}

		if($user === false)
		{
			$ret = 'Database error logging in.';
		}
		elseif($ipBlocked || $lockedOut)
		{
			$ret = $tooManyLoginsMsg;
		}else{
			if(!empty($user) && password_verify($password, $user[0]['password_hash']))
			{
				$user = $user[0];
				if(!$user['enabled'])
				{
					$ret = 'Your account is currently disabled and can only be re-enabled by staff.';
				}else{
					query($db, 'UPDATE `ki_users` SET `last_active`=NOW() WHERE `id`=? LIMIT 1',
						array($user['id']), 'updating user last active');
					$user = query($db, 'SELECT `id`, `email`, `email_verified`, `password_hash`, `enabled`, UNIX_TIMESTAMP(`last_active`) AS last_active FROM `ki_users` WHERE `username`=? LIMIT 1',
						array($username), 'reloading user info afer updating it');
					$user = $user[0];
					query($db, 'DELETE FROM `ki_failedLogins` WHERE `inputUsername`=?',
						array($username), 'resetting failed login count for username on successful login');
					User::$current = new User($user['id'], $username, $user['email'], $user['email_verified'], $user['password_hash'], $user['enabled'], $user['last_active']);
					if(Session::$current !== NULL)
					{
						$sidPair = generateSessionId();
						$sid = $sidPair[0];
						$sidHash = $sidPair[1];
						query($db, 'UPDATE `ki_sessions` SET `id_hash`=?,`user`=?,`last_active`=NOW(),`last_id_reissue`=NOW(),`remember`=? WHERE `id_hash`=? LIMIT 1',
							array($sidHash, $user['id'], $remember, Session::$current->id_hash), 'reissuing session ID and updating session last active');
						//todo: execute hooks for session id change
						$session = query($db, 'SELECT `id_hash`,`user`,UNIX_TIMESTAMP(`established`) AS established,UNIX_TIMESTAMP(`last_active`) AS last_active,`remember`,UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
							array($sidHash), 'reloading session data after updating it');
						$session = $session[0];
						Session::$current = new Session($sid, $sidHash, $ip, $fingerprint, $session['established'], $session['last_active'], $session['remember'], $session['last_id_reissue']);
						$cookieParams = array('id', $sid, sessionExpiry($session['established'], $session['remember'], $session['last_active']), '/', '', true, true);
					}
				}
			}else{
				$ret = 'Invalid credentials.';
				query($db, 'INSERT INTO `ki_failedLogins` SET `inputUsername`=?,`ip`=?,`when`=NOW()',
					array($username, $ipData['id']), 'logging bad login');
				$loggedABadAttempt = true;
			}
		}
	}
	
	if($loggedABadAttempt)
	{
		$badLogins = query($db, 'SELECT COUNT(DISTINCT `inputUsername`) AS total FROM `ki_failedLogins` WHERE `ip`=? AND `when`>FROM_UNIXTIME(?)',
			array($ipData['id'], $pwWindowBegin), 'checking total bad logins from IP');
		$badSessions = query($db, 'SELECT COUNT(DISTINCT `inputSessionId`) AS total FROM `ki_failedSessions` WHERE `ip`=? AND `when`>FROM_UNIXTIME(?)',
			array($ipData['id'], $accountsWindowBegin), 'checking distinct bad session IDs from IP');
		$badAccounts = 0;
		if($badLogins !== false) $badAccounts += $badLogins[0]['total'];
		if($badSessions !== false) $badAccounts += $badSessions[0]['total'];
		if($badAccounts > $accMax)
		{
			$blockUntil = time() + (\ki\config()['limits']['ipBlock_minutes']*60);
			query($db, 'UPDATE `ki_IPs` SET `block_until`=FROM_UNIXTIME(?) WHERE `id`=?',
				array($blockUntil, $ipData['id']), 'blocking IP');
			$ret = ($username !== NULL) ? $tooManyLoginsMsg : true;
			$ipBlocked = true;
		}
	}
	
	if(($logout || $ipBlocked) && Session::$current !== NULL)
	{
		query($db, 'DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
			array(Session::$current->id_hash), 'logging out');
		Session::$current = NULL;
		User::$current = NULL;
	}
	
	//If no session was attached, make a new one now
	//If no user was loaded, make it an anonymous session
	if(Session::$current === NULL && !$ipBlocked)
	{
		$sidPair = generateSessionId();
		$sid = $sidPair[0];
		$sidHash = $sidPair[1];
		$user = (User::$current === NULL) ? NULL : User::$current->id;
		$sessionRemember = ($user === NULL) ? false : $remember;
		$newRes = query($db, 'INSERT INTO `ki_sessions` SET `id_hash`=?,`user`=?,`ip`=?,`fingerprint`=?,`established`=NOW(),`last_active`=NOW(),`remember`=?,`last_id_reissue`=NOW()',
			array($sidHash, $user, $ipData['id'], $fingerprint, $sessionRemember), 'creating new session');
		if($newRes !== 1)
		{
			$ret = false;
		}else{
			$session = query($db, 'SELECT UNIX_TIMESTAMP(`established`) AS established, UNIX_TIMESTAMP(`last_active`) AS last_active, `remember`, UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
				array($sidHash), 'Getting time info from new session');
			if($session !== false && is_array($session) && !empty($session))
			{
				$session = $session[0];
				Session::$current = new Session($sid, $sidHash, $ip, $fingerprint, $session['established'], $session['last_active'], $session['remember'], $session['last_id_reissue']);
				$cookieParams = array('id', $sid, sessionExpiry($session['established'], $session['remember'], $session['last_active']), '/', '', true, true);
			}
		}
	}
	
	if(User::$current !== NULL) //if logged in as a user, load their permissions
	{
		$perms = query($db,
			'SELECT `name` FROM `ki_permissions` WHERE `id` IN('
			. 'SELECT `permission` FROM `ki_permissionsOfGroup` WHERE `group` IN('
			. 'SELECT `group` FROM `ki_groupsOfUser` WHERE `user`=?))',
			array(User::$current->id), 'getting permissions list');
		if($perms !== false)
		{
			foreach($perms as $row)
			{
				User::$current->permissions[] = $row['name'];
			}
		}
	}
	
	if(Session::$current !== NULL && $cookieParams !== NULL)
	{
		setcookie(...$cookieParams);
	}else{
		/*If we completely failed to give them even an anonymous session then at least blank the cookie
		to prevent any previous cookie from generating bad session attach attempts */
		setcookie('id', '', time()-86400, '/', '', true, true);
		if($ret === true) $ret = false;
	}
	
	$db->commit(); $db->autocommit(true);
	return $ret;
}


/**
* Generates a new session ID; guaranteed to be unique
* because it checks against the database.
* Does not write anything to the database.
*
* @return an array($sessionID, $hashedSessionID)
*/
function generateSessionId()
{
	$db = \ki\db();
	$sid = '';
	$sid_hash = '';
	do{
		$sid = random_str(32);
		$sid_hash = pHash($sid);
		$dups = query($db, 'SELECT `id_hash` FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
			array($sid_hash), 'checking for duplicate session ID hash');
	}while(!empty($dups));
	return array($sid, $sid_hash);
}

/*
* Fingerprint the client machine as well as can be done using only server side code.
* Sessions require this to be stable over their lifetime;
* if a user's fingerprint changes, the user gets logged out.
*/
function generateFingerprint()
{
	return $_SERVER['HTTP_USER_AGENT'];
}

/*
* Performs a cryptographically secure hash with a computational factor
* good enough for passwords. Actual password hashing should use PHP's builtin
* bcrypt-powered function. This function is for cases where that won't work well,
* such as hashing the session ID.
*/
function pHash($input, $salt='dkljbhf3948go07hf7g578')
{
	// output length 128
	return hash_pbkdf2('whirlpool', $input, $salt, 1000);
}

/**
* Calculates when a session will expire, assuming no more activity.
* Takes the sooner of the idle timeout and absolute timeout.
*
* @param established timestamp for beginning of session
* @param remember whether the session has Remember enabled
* @param last_active timstamp from which to calculate idle timeout. Leave NULL to use the current time; eg. when starting or refreshing a session. Specify a time to check whether an old session has expired or not without refreshing it.
* @return timestamp for when the session should expire
*/
function sessionExpiry(string $established, bool $remember, int $last_active = NULL)
{
	if($last_active === NULL) $last_active = time();
	$config = \ki\config()['sessions'];
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

function dataTable_editUsers()
{
	$userRow_hashPassword = function(&$row)
	{
		$row['password_hash'] = \password_hash($row['password_hash'], PASSWORD_BCRYPT);
		return true;
	};
	
	$reduceTextSize = function($text)
	{
		return '<span style="font-size:50%;">' . $text . '</span>';
	};
		
	$fields = array();
	$fields[] = new DataTableField('id', NULL, NULL, true, false, false);
	$fields[] = new DataTableField('username', NULL, NULL, true, true, true);
	$fields[] = new DataTableField('email', NULL, NULL, true, true, true, array('type' => 'email'));
	$fields[] = new DataTableField('email_verified', NULL, NULL, true, false, false);
	$fields[] = new DataTableField('password_hash', NULL, NULL, true, false, true, array(), $reduceTextSize);
	$fields[] = new DataTableField('enabled', NULL, NULL, true, true, true);
	$fields[] = new DataTableField('last_active', NULL, NULL, true, false, NULL);
	$events = new DataTableEventCallbacks(NULL, NULL, NULL, $userRow_hashPassword, NULL, NULL);
	return new DataTable('users', 'ki_users', $fields, true, true, false, 50, false, false, false, false, $events, NULL);
}

class User
{
	static $current = NULL;

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

class Session
{
	static $current = NULL;
	
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