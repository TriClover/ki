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
* If parameters are passed, process a user login attempt with them first.
* Otherwise, see if they provided a valid ID for an existing session.
* If the user ends up not logged in, give them a new anonymous session.
* Also check session expiry and SID reissue threshold
*
* @param logout if true, ignore all other parameters and terminate any existing session before doing anything else.
*
* @return string on failed login attempt using username/password, true otherwise.
*/
function checkLogin($username=NULL, $password=NULL, bool $remember=true, bool $logout=false)
{
	$db = \ki\db();
	$ip = $_SERVER['REMOTE_ADDR'];
	$fingerprint = generateFingerprint();
	$db->begin_transaction(MYSQLI_TRANS_START_READ_WRITE);
	$ret = true;
	
	if($logout) {$username=NULL; $password=NULL;}
	
	//process a login attempt
	if($username !== NULL && $password !== NULL)
	{
		$userRow = query($db, 'SELECT * FROM `ki_users` WHERE `username`=? LIMIT 1',
			array($username), 'checking login');
		
		if($userRow !== false)
		{
			if(empty($userRow) || !password_verify($password, $userRow[0]['password_hash']))
			{
				//Bad credentials provided, log failed login
				query($db, 'INSERT INTO `ki_failedLogins` SET `inputUsername`=?,`ip`=INET6_ATON(?),`when`=NOW()',
					array($username, $ip), 'logging bad login');
				$ret = 'Invalid credentials.';
			}else{
				//Correct credentials provided
				$user = $userRow[0];
				query($db, 'DELETE FROM `ki_failedLogins` WHERE `inputUsername`=?',
					array($username), 'resetting failed login count for username');
				User::$current = new User($user['id'], $user['username'], $user['email'], $user['email_verified'], $user['password_hash'], $user['enabled'], $user['lockout_until'], $user['last_active']);
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
			
				//start a session
				$sidPair = generateSessionId();
				$sid = $sidPair[0];
				$sid_hash = $sidPair[1];
				$sessionResult = query($db, 'INSERT INTO `ki_sessions` SET `id_hash`=?,`user`=?,`ip`=INET6_ATON(?),`fingerprint`=?,`established`=NOW(),`last_active`=NOW(),`remember`=?,`last_id_reissue`=NOW()',
					array($sid_hash, User::$current->id, $ip, $fingerprint, $remember),
					'creating session');
				if($sessionResult !== false)
				{
					$session = query($db, 'SELECT * FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
						array($sid_hash), 'getting generated info for session created in previous query');
					if($session === false || empty($session))
					{
						$ret = 'Error accessing new session';
					}else{
						$session = $session[0];
						Session::$current = new Session($sid, $sid_hash, $ip, $fingerprint, $session['established'], $session['last_active'], $remember, $session['last_id_reissue']);
						$expiresTimestamp = sessionExpiry(Session::$current->established, Session::$current->remember);
						setcookie('id', $sid, $expiresTimestamp, '/', '', true, true);
					}
				}
				query($db, 'UPDATE `ki_users` SET `last_active`=NOW() WHERE `id`=? LIMIT 1',
					array(User::$current->id), 'updating user last_active');
			}
		}else{
			$ret = 'Database error logging in.';
		}
	}
	elseif(isset($_COOKIE['id']) && !empty($_COOKIE['id']))
	{
		//process attempt to attach to existing session
		$sid = $_COOKIE['id'];
		$sidHash = pHash($_COOKIE['id']);
		$session = query($db, 'SELECT * FROM `ki_sessions` WHERE `id_hash`=? AND `ip`=INET6_ATON(?) AND `fingerprint`=? LIMIT 1',
			array($sidHash, $ip, $fingerprint), 'looking up session requested by user');
		if($session !== false && !empty($session))
		{
			$session = $session[0];
			//Real session detected
			$tsLastActive = \DateTime::createFromFormat('Y-m-d H:i:s', $session['last_active'])->getTimestamp();
			$tsLastReissue = \DateTime::createFromFormat('Y-m-d H:i:s', $session['last_id_reissue'])->getTimestamp();
			
			//if session expired, or logout was selected, terminate the session
			if($logout || (time() > sessionExpiry($session['established'], $remember, $tsLastActive)))
			{
				setcookie('id', '', time()-86400, '/', '', true, true);
				query($db, 'DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1', array($sidHash), 'cleaning up expired session');
			}else{
				//check sid reissue time and update last active
				$tsReissue = $tsLastReissue + (\ki\config()['sessions']['reissue_minutes']*60);
				if($tsReissue < time())
				{
					$sidPair = generateSessionId();
					$sid = sidPair[0];
					$sidHash = sidPair[1];
					query($db, 'UPDATE `ki_sessions` SET `id_hash`=?,`last_active`=NOW(),`last_id_reissue`=NOW() WHERE `id_hash`=? LIMIT 1',
						array($sidHash, $session['id_hash']), 'reissuing session ID');
				}else{
					query($db, 'UPDATE `ki_sessions` SET `last_active`=NOW() WHERE `id_hash`=? LIMIT 1',
						array($session['id_hash']), 'updating last active');
				}
				$session = query($db, 'SELECT * FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
					array($sidHash), 'reloading session after updates');
				if($session !== false && !empty($session))
				{
					$session = $session[0];
					setcookie('id', $sid, sessionExpiry($session['established'], $session['remember']), '/', '', true, true);
				
					//load session/user data
					Session::$current = new Session($sid, $sidHash, $ip, $fingerprint, $session['established'], $session['last_active'], $session['remember'], $session['last_id_reissue']);
					query($db, 'UPDATE `ki_users` SET `last_active`=NOW() WHERE `id`=? LIMIT 1',
						array($session['user']), 'updating user last_active');
					$user = query($db, 'SELECT * FROM `ki_users` WHERE `id`=? LIMIT 1',
						array($session['user']), 'getting user data for session');
					if($user !== false && !empty($user))
					{
						$user = $user[0];
						User::$current = new User($user['id'], $user['username'], $user['email'], $user['email_verified'], $user['password_hash'], $user['enabled'], $user['lockout_until'], $user['last_active']);
					}
				}
			}
		}
		elseif($session !== false && empty($session))
		{
			//Bad session connection attempt: count as bad login attempt
			query($db, 'INSERT INTO `ki_failedLogins` SET `inputUsername`=?,`ip`=INET6_ATON(?),`when`=NOW()',
				array($sid,$ip), 'logging bad session attach attempt');
		}
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
* @param established DateTime string for beginning of session
* @param remember whether the session has Remember enabled
* @param last_active timstamp from which to calculate idle timeout. Leave NULL to use the current time; eg. when starting or refreshing a session. Specify a time to check whether an old session has expired or not without refreshing it.
* @return timestamp for when the session should expire
*/
function sessionExpiry(string $established, bool $remember, int $last_active = NULL)
{
	if($last_active === NULL) $last_active = time();
	$established = \DateTime::createFromFormat('Y-m-d H:i:s', $established)->getTimestamp();
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
	$fields = array();
	$fields[] = new DataTableField('id', NULL, NULL, true, false, false);
	$fields[] = new DataTableField('username', NULL, NULL, true, true, true);
	$fields[] = new DataTableField('email', NULL, NULL, true, true, true, array('type' => 'email'));
	$fields[] = new DataTableField('email_verified', NULL, NULL, true, false, false);
	$fields[] = new DataTableField('password_hash', NULL, NULL, true, false, true, array(), '\ki\security\reduceTextSize');
	$fields[] = new DataTableField('enabled', NULL, NULL, true, true, true);
	$fields[] = new DataTableField('lockout_until', NULL, NULL, true, true, true);
	$fields[] = new DataTableField('last_active', NULL, NULL, true, false, NULL);
	$events = new DataTableEventCallbacks(NULL, NULL, NULL, '\ki\security\userRow_hashPassword', NULL, NULL);
	return new DataTable('users', 'ki_users', $fields, true, true, false, 3, false, false, false, false, $events, NULL);
}

function userRow_hashPassword(&$row)
{
	$row['password_hash'] = \password_hash($row['password_hash'], PASSWORD_BCRYPT);
	return true;
}

function reduceTextSize($text)
{
	return '<span style="font-size:50%;">' . $text . '</span>';
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
	public $lockout_until;
	public $last_active;
	
	public $permissions = array();
	
	function __construct($id, $username, $email, $email_verified, $password_hash, $enabled, $lockout_until, $last_active)
	{
		$this->id             = $id;
		$this->username       = $username;
		$this->email          = $email;
		$this->email_verified = $email_verified;
		$this->password_hash  = $password_hash;
		$this->enabled        = $enabled;
		$this->lockout_until  = $lockout_until;
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