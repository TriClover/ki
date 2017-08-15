<?php
namespace mls\ki\Security;
use \mls\ki\Database;
use \mls\ki\Mail;
use \mls\ki\Util;
use \mls\ki\Widgets\DataTable;
use \mls\ki\Widgets\DataTableField;
use \mls\ki\Widgets\DataTableEventCallbacks;
use \mls\ki\Widgets\Menu;
use \mls\ki\Widgets\MenuItem;
use \mls\ki\Widgets\TargetTabber;

class Authenticator
{
	//Shown for all signup attempts, successful or not
	const msg_AccountReg = 'Request recieved - check your email';
	const msg_AccountVerifyInstruction = 'Finish creating your account by clicking the following link to verify your email address:';
	//ensure that all auth related DB error messages are the same to avoid leaking state information
	const msg_databaseError = 'Database error.';
	
	//Instances of the User, Session, and Request classes describing the current request, populated by checkLogin
	public static $user;
	public static $session;
	public static $request;

	/**
	* Checks session status and fills the user/session globals with the info that pages need
	* to check authorization and/or track the user.
	* First, see if they provided a valid ID for an existing session.
	* If username/password are given, process a user login attempt with them.
	* If the user ends up not logged in, give them a new anonymous session.
	* Also check session expiry, SID reissue threshold, account lockout, etc.
	*
	* @param params Array of parameters to inject. See "Set defaults" section for valid keys
	* @return string on failed login attempt using username/password, false on errors so serious even an anonymous session couldn't be attached, true otherwise.
	*/
	public function checkLogin(array $params = array())
	{
		//set defaults
		//Priority: 1) Injected values 2) POST data by default names 3) hardcoded defaults signifying no action
		$username = NULL;
		$password = NULL;
		$remember = true;
		$logout = false;
		if(isset($params['username'])) $username = $params['username']; elseif(isset($_POST['login_username'])) $username = $_POST['login_username'];
		if(isset($params['password'])) $password = $params['password']; elseif(isset($_POST['login_password'])) $password = $_POST['login_password'];
		if(isset($params['remember'])) $remember = $params['remember']; elseif(isset($_POST['login_remember'])) $remember = $_POST['login_remember'];
		if(isset($params['logout'  ])) $logout   = $params['logout'  ]; elseif(isset($_POST['logout'        ])) $logout   = true;
		
		//gather data
		$db = \mls\ki\Database::db();
		Authenticator::$request = new Request();
		Authenticator::$request->fingerprint = Authenticator::generateFingerprint();
		Authenticator::$request->ip = $_SERVER['REMOTE_ADDR'];
		$pwWindowBegin = time() - (\mls\ki\Config::get()['limits']['passwordAttemptWindow_minutes']*60);
		$pwMax = \mls\ki\Config::get()['limits']['maxPasswordAttempts'];
		$accountsWindowBegin = time() - (\mls\ki\Config::get()['limits']['accountAttemptWindow_minutes']*60);
		$accMax = \mls\ki\Config::get()['limits']['maxAccountAttempts'];
		$tooManyLoginsMsg = 'Too many login attempts.';
		
		//set flags
		$db->connection->begin_transaction();
		$ret = true;
		$cookieParams = NULL;
		$loggedABadAttempt = false;
		
		//Check current standing of requestor's IP
		$ipData = $db->query('SELECT `id`,INET6_NTOA(`ip`) AS ip, UNIX_TIMESTAMP(`block_until`) AS block_until FROM ki_IPs WHERE `ip`=INET6_ATON(?) LIMIT 1',
			array(Authenticator::$request->ip), 'checking IP status');
		if($ipData === false) {$db->commit(); $db->autocommit(true); return false;}
		if(empty($ipData)) //If IP is newly encountered, add it to the list
		{
			$ipIns = $db->query('INSERT INTO `ki_IPs` SET `ip`=INET6_ATON(?)', array(Authenticator::$request->ip), 'recording new IP');
			if($ipIns !== 1) {$db->commit(); $db->autocommit(true); return false;}
			$ipData = array('id' => $db->insert_id, 'ip' => Authenticator::$request->ip, 'block_until' => NULL);
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
				$sidHash = Authenticator::pHash($sid);
				$session = $db->query('SELECT `id_hash`,`user`,UNIX_TIMESTAMP(`established`) AS established,UNIX_TIMESTAMP(`last_active`) AS last_active,`remember`,UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? AND `ip`=? AND `fingerprint`=? LIMIT 1',
					array($sidHash, $ipData['id'], Authenticator::$request->fingerprint), 'looking up session requested by user');
				if(is_array($session) && !empty($session)) //If SID is valid
				{
					$session = $session[0];
					//if idle/abs timeout reached
					if(time() >= Authenticator::sessionExpiry($session['established'], $session['remember'], $session['last_active']))
					{
						$db->query('DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
							array($sidHash), 'cleaning up expired session');
					}else{
						if($session['user'] !== NULL)
						{
							$db->query('UPDATE `ki_users` SET `last_active`=NOW() WHERE `id`=?',
								array($session['user']), 'udpating user last active');
							$user = $db->query('SELECT `username`, `email`, `email_verified`, `password_hash`, `enabled`, UNIX_TIMESTAMP(`last_active`) AS last_active FROM `ki_users` WHERE `id`=? LIMIT 1',
								array($session['user']), 'loading user for session');
							if(is_array($user) && !empty($user))
							{
								$user = $user[0];
								Authenticator::$user = new User($session['user'], $user['username'], $user['email'], $user['email_verified'], $user['password_hash'], $user['enabled'], $user['last_active']);
							}
						}
						if(Authenticator::$user !== NULL && !Authenticator::$user->enabled)
						{
							Authenticator::$user = NULL;
							$db->query('DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
								array($sidHash), 'deleting session of disabled user');
						}else{
							$reissueSeconds = \mls\ki\Config::get()['sessions']['reissue_minutes']*60;
							$reissueTime = $session['last_id_reissue'] + $reissueSeconds;
							if($reissueTime <= time()) //if time for reissuing session id, do that while updating last active
							{
								$sidPair = Authenticator::generateSessionId();
								$sid = $sidPair[0];
								$sidHash = $sidPair[1];
								$db->query('UPDATE `ki_sessions` SET `id_hash`=?,`last_active`=NOW(),`last_id_reissue`=NOW() WHERE `id_hash`=? LIMIT 1',
									array($sidHash, $session['id_hash']), 'reissuing session ID and updating session last active');
							}else{
								$db->query('UPDATE `ki_sessions` SET `last_active`=NOW() WHERE `id_hash`=? LIMIT 1',
									array($session['id_hash']), 'updating session last active');
							}
							$session = $db->query('SELECT `id_hash`,`user`,UNIX_TIMESTAMP(`established`) AS established,UNIX_TIMESTAMP(`last_active`) AS last_active,`remember`,UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
								array($sidHash), 'reloading session data after updating it');
							if($session === false)
							{
								Authenticator::$user = NULL;
							}else{
								$session = $session[0];
								Authenticator::$session = new Session($sid, $sidHash, Authenticator::$request->ip, Authenticator::$request->fingerprint, $session['established'], $session['last_active'], $session['remember'], $session['last_id_reissue']);
								$cookieParams = array('id', $sid, Authenticator::sessionExpiry($session['established'], $session['remember'], $session['last_active']), '/', '', true, true);
							}
						}
					}
				}else{
					//if the given sid was confirmed invalid (ie not returned as valid and there was no DB error)
					if($session !== false)
					{
						$db->query('INSERT INTO ki_failedSessions SET `input`=?,`ip`=?,`when`=NOW()',
							array($sid, $ipData['id']), 'logging invalid session attach attempt');
						$loggedABadAttempt = true;
					}
				}
			}
		}
		
		//check for nonces
		//email verification nonce
		if(isset($_GET['ki_nonce']) && !empty($_GET['ki_nonce']) && !$ipBlocked)
		{
			$nonce = $_GET['ki_nonce'];
			$nonceHash = Authenticator::pHash($nonce);
			$nRes = $db->query('SELECT `ki_nonces`.`user` AS user,UNIX_TIMESTAMP(`ki_nonces`.`created`) AS created, `ki_users`.`username` AS name FROM `ki_nonces` LEFT JOIN `ki_users` ON `ki_nonces`.`user`=`ki_users`.`id` WHERE `nonce_hash`=? AND `purpose`="email_verify" LIMIT 1',
				array($nonceHash), 'checking for email verification nonce');
			if($nRes !== false)
			{
				if(empty($nRes)) //Tried non-existent nonce. Count as bad session attach attempt.
				{
					$db->query('INSERT INTO ki_failedSessions SET `input`=?,`ip`=?,`when`=NOW()',
						array($sid, $ipData['id']), 'logging invalid nonce');
					$loggedABadAttempt = true;
				}else{
					$nonceCreated = $nRes[0]['created'];
					$nonceUser = $nRes[0]['user'];
					$nonceUsername = $nRes[0]['user'];
					$nonceExpires = $nonceCreated + (\mls\ki\Config::get()['limits']['nonceExpiry_hours'] * 60 * 60);
					if($nonceExpires < time())
					{
						Authenticator::$session->systemMessages[] = 'The email verification link you clicked was expired. '
							. 'Try doing whatever you were doing again to get a new link. Do not click that same link again.';
					}else{
						$db->query('UPDATE `ki_users` SET `email_verified`=1 WHERE `id`=? LIMIT 1',
							array($nonceUser), 'setting email verification flag to TRUE for user');
						Authenticator::$request->verifiedEmail = true;
						Authenticator::$request->systemMessages[] = 'Email verified.';
						//log in the user
						$potentialCookie = Authenticator::loginUser($nonceUser, $remember);
						if($potentialCookie !== NULL) $cookieParams = $potentialCookie;
					}
					$db->query('DELETE FROM `ki_nonces` WHERE `nonce_hash`=? LIMIT 1',
						array($nonceHash), 'Deleting used nonce');
				}
			}
		}
		
		//if user/pass provided for login attempt, and GIVEN user not already loaded via recognized session or nonce
		if($username !== NULL && $password !== NULL && (Authenticator::$user === NULL || Authenticator::$user->username != $username))
		{
			if(Authenticator::$user !== NULL) //different user loaded
			{
				$db->query('DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
					array(Authenticator::$session->id_hash), 'terminating session being replaced');
				Authenticator::$session = NULL;
				Authenticator::$user = NULL;
				Authenticator::$request->verifiedEmail = false;
			}
			
			$user = $db->query('SELECT `id`, `email`, `email_verified`, `password_hash`, `enabled`, UNIX_TIMESTAMP(`last_active`) AS last_active FROM `ki_users` WHERE `username`=? LIMIT 1',
				array($username), 'getting user info for login attempt');
			$badLogins = $db->query('SELECT COUNT(*) AS total FROM `ki_failedLogins` WHERE `inputUsername`=? AND `when`>FROM_UNIXTIME(?)',
				array($username, $pwWindowBegin), 'checking total bad logins for account');
			$lockedOut = false;
			if($badLogins !== false && !empty($badLogins) && $badLogins[0]['total'] > $pwMax)
			{
				$lockedOut = true;
			}

			if($user === false)
			{
				$ret = \ki\security\Authenticator::msg_databaseError;
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
						if(!$user['email_verified'])
						{
							$idPair = Authenticator::generateNonceId();
							$nid = $idPair[0];
							$nidHash = $idPair[1];
							$resNon = $db->query('INSERT INTO `ki_nonces` SET `nonce_hash`=?,`user`=?,`session`=NULL,`created`=NOW(),`purpose`="email_verify"',
								array($nidHash, $user['id']), 'adding nonce for email verify on duplicate registration');
							if($resNon !== false)
							{
								$mail = new \PHPMailer\PHPMailer\PHPMailer;
								$mail->From = 'noreply@' . $_SERVER['SERVER_NAME'];
								$mail->FromName = $site . ' Account Management';
								$mail->addAddress($user['email']);
								$mail->Subject = $site . ' Account Creation';
								$link = \mls\ki\Util::getUrl() . '?ki_nonce=' . $nid;
								$mail->Body = Authenticator::msg_AccountVerifyInstruction . "\n" . $link;
								Mail::send($mail);
								$ret = 'Check your email for instructions on finishing the creation of your account.';
							}else{
								$ret = 'Error generating nonce.';
							}
						}else{
							$potentialCookie = Authenticator::loginUser($user['id'], $remember);
							if($potentialCookie !== NULL) $cookieParams = $potentialCookie;
						}
					}
				}else{
					$ret = 'Invalid credentials.';
					$db->query('INSERT INTO `ki_failedLogins` SET `inputUsername`=?,`ip`=?,`when`=NOW()',
						array($username, $ipData['id']), 'logging bad login');
					$loggedABadAttempt = true;
				}
			}
		}
		
		//If there was any bad auth attempt, apply limits
		if($loggedABadAttempt)
		{
			$badLogins = $db->query('SELECT COUNT(DISTINCT `inputUsername`) AS total FROM `ki_failedLogins` WHERE `ip`=? AND `when`>FROM_UNIXTIME(?)',
				array($ipData['id'], $pwWindowBegin), 'checking total bad logins from IP');
			$badSessions = $db->query('SELECT COUNT(DISTINCT `input`) AS total FROM `ki_failedSessions` WHERE `ip`=? AND `when`>FROM_UNIXTIME(?)',
				array($ipData['id'], $accountsWindowBegin), 'checking distinct bad session IDs from IP');
			$badAccounts = 0;
			if($badLogins !== false) $badAccounts += $badLogins[0]['total'];
			if($badSessions !== false) $badAccounts += $badSessions[0]['total'];
			if($badAccounts > $accMax)
			{
				$blockUntil = time() + (\mls\ki\Config::get()['limits']['ipBlock_minutes']*60);
				$db->query('UPDATE `ki_IPs` SET `block_until`=FROM_UNIXTIME(?) WHERE `id`=?',
					array($blockUntil, $ipData['id']), 'blocking IP');
				$ret = ($username !== NULL) ? $tooManyLoginsMsg : true;
				$ipBlocked = true;
			}
		}
		
		if(($logout || $ipBlocked) && Authenticator::$session !== NULL)
		{
			$db->query('DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
				array(Authenticator::$session->id_hash), 'logging out');
			Authenticator::$session = NULL;
			Authenticator::$user = NULL;
		}
		
		//If no session was attached, make a new one now
		//If no user was loaded, make it an anonymous session
		if(Authenticator::$session === NULL && !$ipBlocked)
		{
			$sidPair = Authenticator::generateSessionId();
			$sid = $sidPair[0];
			$sidHash = $sidPair[1];
			$user = (Authenticator::$user === NULL) ? NULL : Authenticator::$user->id;
			$sessionRemember = ($user === NULL) ? false : $remember;
			$newRes = $db->query('INSERT INTO `ki_sessions` SET `id_hash`=?,`user`=?,`ip`=?,`fingerprint`=?,`established`=NOW(),`last_active`=NOW(),`remember`=?,`last_id_reissue`=NOW()',
				array($sidHash, $user, $ipData['id'], Authenticator::$request->fingerprint, $sessionRemember), 'creating new session');
			if($newRes !== 1)
			{
				$ret = false;
			}else{
				$session = $db->query('SELECT UNIX_TIMESTAMP(`established`) AS established, UNIX_TIMESTAMP(`last_active`) AS last_active, `remember`, UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
					array($sidHash), 'Getting time info from new session');
				if($session !== false && is_array($session) && !empty($session))
				{
					$session = $session[0];
					Authenticator::$session = new Session($sid, $sidHash, Authenticator::$request->ip, Authenticator::$request->fingerprint, $session['established'], $session['last_active'], $session['remember'], $session['last_id_reissue']);
					$cookieParams = array('id', $sid, Authenticator::sessionExpiry($session['established'], $session['remember'], $session['last_active']), '/', '', true, true);
				}
			}
		}
		
		if(Authenticator::$user !== NULL) //if logged in as a user, load their permissions
		{
			$perms = $db->query('SELECT `name` FROM `ki_permissions` WHERE `id` IN('
				. 'SELECT `permission` FROM `ki_permissionsOfGroup` WHERE `group` IN('
				. 'SELECT `group` FROM `ki_groupsOfUser` WHERE `user`=?))',
				array(Authenticator::$user->id), 'getting permissions list');
			if($perms !== false)
			{
				foreach($perms as $row)
				{
					Authenticator::$user->permissions[$row['name']] = true;
				}
			}
		}
		
		if(Authenticator::$session !== NULL && $cookieParams !== NULL)
		{
			setcookie(...$cookieParams);
		}else{
			/*If we completely failed to give them even an anonymous session then at least blank the cookie
			to prevent any previous cookie from generating bad session attach attempts */
			setcookie('id', '', time()-86400, '/', '', true, true);
			if($ret === true) $ret = false;
		}
		
		$db->connection->commit(); $db->connection->autocommit(true);
		if(is_string($ret))
		{
			Authenticator::$request->systemMessages[] = $ret;
			return true;
		}
		return $ret;
	}

	/**
	* Logs in the user with the given ID.
	* If there is already an active session, change it to be owned by that user.
	* If there is no active session, do nothing session-related; the caller must create a new session.
	* No security checks are performed here; do them before calling this function.
	* @param id the user ID
	* @param remember whether to enable "remember" feature on their session, only applicable if there is an existing session that this user will take over.
	* @return the value to store in cookieParams, if any, otherwise NULL.
	*/
	private function loginUser($id, $remember)
	{
		$db = \mls\ki\Database::db();
		$db->query('UPDATE `ki_users` SET `last_active`=NOW() WHERE `id`=? LIMIT 1',
			array($id), 'updating user last active');
		$user = $db->query('SELECT `id`, `username`, `email`, `email_verified`, `password_hash`, `enabled`, UNIX_TIMESTAMP(`last_active`) AS last_active FROM `ki_users` WHERE `id`=? LIMIT 1',
			array($id), 'reloading user info afer updating it');
		$user = $user[0];
		$db->query('DELETE FROM `ki_failedLogins` WHERE `inputUsername`=?',
			array($user['username']), 'resetting failed login count for username on successful login');
		Authenticator::$user = new User($user['id'], $user['username'], $user['email'], $user['email_verified'], $user['password_hash'], $user['enabled'], $user['last_active']);
		if(Authenticator::$session !== NULL)
		{
			//If there is an active session, the logged in user will take it over
			//The session will get a new session ID to prevent privilege escalation attacks
			$sidPair = Authenticator::generateSessionId();
			$sid = $sidPair[0];
			$sidHash = $sidPair[1];
			$db->query('UPDATE `ki_sessions` SET `id_hash`=?,`user`=?,`last_active`=NOW(),`last_id_reissue`=NOW(),`remember`=? WHERE `id_hash`=? LIMIT 1',
				array($sidHash, $user['id'], $remember, Authenticator::$session->id_hash), 'reissuing session ID and updating session last active');
			$session = $db->query('SELECT `id_hash`,`user`,UNIX_TIMESTAMP(`established`) AS established,UNIX_TIMESTAMP(`last_active`) AS last_active,`remember`,UNIX_TIMESTAMP(`last_id_reissue`) AS last_id_reissue FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
				array($sidHash), 'reloading session data after updating it');
			$session = $session[0];
			Authenticator::$session = new Session($sid, $sidHash, Authenticator::$request->ip, Authenticator::$request->fingerprint, $session['established'], $session['last_active'], $session['remember'], $session['last_id_reissue']);
			$cookieParams = array('id', $sid, Authenticator::sessionExpiry($session['established'], $session['remember'], $session['last_active']), '/', '', true, true);
			return $cookieParams;
		}
		return NULL;
	}
	
	/**
	* Generates a new session ID; guaranteed to be unique
	* because it checks against the database.
	* Does not write anything to the database.
	*
	* @return an array($sessionID, $hashedSessionID)
	*/
	public function generateSessionId()
	{
		return Authenticator::generateHashedId('session');
	}

	/**
	* Generates a new nonce ID; guaranteed to be unique
	* because it checks against the database.
	* Does not write anything to the database.
	*
	* @return an array($sessionID, $hashedSessionID)
	*/
	public function generateNonceId()
	{
		return Authenticator::generateHashedId('nonce');
	}
	
	/**
	* Generates a new hashed ID for the specified table;
	* guaranteed to be unique because it checks against the database.
	* Does not write anything to the database.
	*
	* @param type the type of ID to generate. Valid values: session, nonce
	* @return an array($sessionID, $hashedSessionID)
	*/
	public function generateHashedId(string $type='session')
	{
		$db = Database::db();
		$sid = '';
		$sid_hash = '';
		$fieldname = 'id_hash';
		if($type == 'nonce') $fieldname = 'nonce_hash';
		do{
			$sid = Util::random_str(32);
			$sid_hash = Authenticator::pHash($sid);
			$dups = $db->query('SELECT `' . $fieldname . '` FROM `ki_' . $type .'s` WHERE `' . $fieldname . '`=? LIMIT 1',
				array($sid_hash), 'checking for duplicate ' . $type . ' ID hash');
		}while(!empty($dups));
		return array($sid, $sid_hash);	
	}
	
	/*
	* Fingerprint the client machine as well as can be done using only server side code.
	* Sessions require this to be stable over their lifetime;
	* if a user's fingerprint changes, the user gets logged out.
	*/
	public function generateFingerprint()
	{
		return $_SERVER['HTTP_USER_AGENT'];
	}
	
	/*
	* Performs a cryptographically secure hash with a computational factor
	* good enough for passwords. Actual password hashing should use PHP's builtin
	* bcrypt-powered function instead. This function is for cases where that
	* won't work well, such as hashing the session ID.
	*/
	public function pHash($input, $salt='dkljbhf3948go07hf7g578')
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
	public function sessionExpiry(string $established, bool $remember, int $last_active = NULL)
	{
		if($last_active === NULL) $last_active = time();
		$config = \mls\ki\Config::get()['sessions'];
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
	* Get the HTML for the login form, and process requests from it except login
	*/
	public static function getMarkup_loginForm()
	{
		$user = Authenticator::$user;
		$request = Authenticator::$request;
		$out = '';
		
		Authenticator::$dataTable_register = Authenticator::getDataTable_register();
		Authenticator::$dataTable_register->handleParams();
		
		$outputSpanOpen = '<span style="color:red;font-size:75%;">';
		$sys = $outputSpanOpen . implode(', ', $request->systemMessages) . '</span>';
		$tabberId = 'auth';

		if($user === NULL)
		{
			$regForm = Authenticator::$dataTable_register->getHTML();
			$regForm = str_replace('.php', '.php#auth_Register', $regForm);
			$regForm = str_replace('<ul><li>', $outputSpanOpen, $regForm);
			$regForm = str_replace('</li></ul>', '</span>', $regForm);
			$regForm = str_replace('</li><li>', ', ', $regForm);
			$regOutputStart = mb_strpos($regForm, Authenticator::msg_AccountReg);
			if($regOutputStart !== false)
			{
				$regForm = '<ul><li>' . Authenticator::msg_AccountReg . '</li></ul>';
			}
			
			$coreLogin = '<form name="ki_login" id="ki_login" method="post" action="' . $_SERVER['SCRIPT_NAME'] . '">'
				. '&nbsp;<input type="text" name="login_username" id="login_username" required placeholder="username"/>'
				. '<input type="password" name="login_password" id="login_password" required placeholder="password"/>'
				. '<input type="submit" name="login" id="login" value="Login"/>'
				. '</form>'
				. $sys;
			$tabs = array('Login'=>$coreLogin, 'Register'=>$regForm, 'Forgot Username/Password?' => 'Coming soon<br/>');
			$tabberHeight = '52px';
			
			$tabber = TargetTabber::getHTML($tabberId, $tabs, '350px', 'auto');
			$out .= $tabber;
			$out .= '<style scoped>'
				. '#auth_Register .ki_table>form>div{display:inline-block;float:left;}'
				. '#' . $tabberId . '{float:right;}'
				. '</style>';
		}else{
			$items = array();
			$items[] = new MenuItem('Logout', $_SERVER['SCRIPT_NAME'], array('logout' => '1'));
			$menuButton = '👤 ' . $user->username . '<br/>' . $sys;
			$out .= Menu::getHTML($menuButton, $items, array('float'=>'right', 'height'=>'34px'));
		}

		return $out;
	}
	
	protected function getDataTable_register()
	{
		$reg_beforeAdd = function(&$row)
		{
			$db = \mls\ki\Database::db();
			$site = \mls\ki\Config::get()['general']['sitename'];
			
			//Checks for which the error can be shown on-page instead of via email because there is no security implication
			if($row['password_hash'] != $_POST['confirmRegPassword'])
			{
				return 'Password and confirmation did not match.';
			}

			//Only failure paths will result in a mail being sent but we can set one up here to avoid repetition
			$mail = new \PHPMailer\PHPMailer\PHPMailer;
			$mail->From = 'noreply@' . $_SERVER['SERVER_NAME'];
			$mail->FromName = $site . ' Account Management';
			$mail->addAddress($row['email']);
			
			//turn the password into a hash before storing
			$row['password_hash'] = \password_hash($row['password_hash'], PASSWORD_BCRYPT);

			//check for duplicate email: instead of letting the dataTable show duplicate error on mail field, abort.
			$resMail = $db->query('SELECT `id`,`email_verified` FROM `ki_users` WHERE `email`=? LIMIT 1',
				array($row['email']), 'Checking for duplicate email');
			if($resMail === false) return \mls\ki\Security\Authenticator::msg_databaseError;
			if(!empty($resMail))
			{
				$idPair = Authenticator::generateNonceId();
				$nid = $idPair[0];
				$nidHash = $idPair[1];

				if($resMail[0]['email_verified'])
				{
					$mail->Subject = $site . ' Account Re-registration';
					$mail->Body = 'It looks like you tried to register an account with this email address, but this email address is already associated with an account. If you are having trouble getting into your account, please use the "Forgot password/username?" feature on the site.';
					Mail::send($mail);
				}else{
					$resNon = $db->query('INSERT INTO `ki_nonces` SET `nonce_hash`=?,`user`=?,`session`=NULL,`created`=NOW(),`purpose`="email_verify"',
						array($nidHash, $resMail[0]['id']), 'adding nonce for email verify on duplicate registration');
					if($resNon !== false)
					{
						$mail->Subject = $site . ' Account Creation';
						$link = \mls\ki\Util::getUrl() . '?ki_nonce=' . $nid;
						$mail->Body = \mls\ki\Security\Authenticator::msg_AccountVerifyInstruction . "\n" . $link;
						Mail::send($mail);
					}
				}
				return \mls\ki\Security\Authenticator::msg_AccountReg;
			}
			
			//check for duplicate username with same goals as above check for dup. email
			$resUname = $db->query('SELECT `id` FROM `ki_users` WHERE `username`=? LIMIT 1',
				array($row['username']), 'checking for duplicate username');
			if($resUname === false) return \mls\ki\Security\Authenticator::msg_databaseError;
			if(!empty($resUname))
			{
				$mail->Subject = $site . ' Account Creation';
				$mail->Body = 'You tried to register an account with the username "' . $row['username']
					. '", but that name is already taken. Please try another.';
				Mail::send($mail);
				return \mls\ki\Security\Authenticator::msg_AccountReg;
			}
			
			return true;
		};
		
		$reg_onAdd = function($userPKs)
		{
			$db = \mls\ki\Database::db();
			$site = \mls\ki\Config::get()['general']['sitename'];
			$from = 'noreply@' . $_SERVER['SERVER_NAME'];
		
			$resUser = $db->query('SELECT `email` FROM `ki_users` WHERE `id`=?',
				array($userPKs['id']), 'getting ID of new user');
			if($resUser !== false || !empty($resUser))
			{
				$mail = new \PHPMailer\PHPMailer\PHPMailer;
				$mail->Subject = $site . ' Account Creation';
				$mail->From = $from;
				$mail->FromName = $site . ' Account Management';
				$mail->addAddress($resUser[0]['email']);
				
				$idPair = \mls\ki\Security\Authenticator::generateNonceId();
				$nid = $idPair[0];
				$nidHash = $idPair[1];
				$resNon = $db->query('INSERT INTO `ki_nonces` SET `nonce_hash`=?,`user`=?,`session`=NULL,`created`=NOW(),`purpose`="email_verify"',
					array($nidHash, $userPKs['id']), 'adding nonce for email verify on new account');
				if($resNon !== false)
				{
					$link = \mls\ki\Util::getUrl() . '?ki_nonce=' . $nid;
					$mail->Body = \mls\ki\Security\Authenticator::msg_AccountVerifyInstruction . "\n" . $link;
					Mail::send($mail);
				}
			}
			return \mls\ki\Security\Authenticator::msg_AccountReg;
		};
		
		$formatPassField = function($text, $action)
		{
			if($action == 'add')
			{
				$text = '<span id="passwordTwins">' . $text . ' <input type="password" name="confirmRegPassword" id="confirmRegPassword" placeholder="confirm password" style="margin-left:6px;"/></span>';
				$text .= '<script>
					$("#passwordTwins").closest("form").submit(function(event)
					{
						if($("#confirmRegPassword").val() != $("#passwordTwins input").not("#confirmRegPassword").val())
						{
							alert("Password and confirmation must match.");
							return false;
						}
					});</script>';
			}
			return $text;
		};
		
		$config = \mls\ki\Config::get()['policies'];
		
		$passwordConstraints = array('pattern' => $config['passwordRegex'],
									 'title' => $config['passwordRegexDescription'],
									 'type' => 'password');
		
		$fields = array();
		$fields[] = new DataTableField('id', NULL, 'Register', false, false, false);
		$fields[] = new DataTableField('username', NULL, NULL, true, false, true);
		$fields[] = new DataTableField('email', NULL, NULL, true, false, true, array('type' => 'email'));
		$fields[] = new DataTableField('email_verified', NULL, NULL, false, false, 0);
		$fields[] = new DataTableField('password_hash', NULL, 'password', true, false, true, $passwordConstraints, $formatPassField);
		$fields[] = new DataTableField('enabled', NULL, NULL, false, false, 1);
		$fields[] = new DataTableField('last_active', NULL, NULL, false, false, false);
		$events = new DataTableEventCallbacks($reg_onAdd, NULL, NULL, $reg_beforeAdd, NULL, NULL);
		return new DataTable('register', 'ki_users', $fields, true, false, false, 0, false, false, false, false, $events, NULL);
	}
	
	/**
	* @return a DataTable that provides an admin interface for editing users.
	*/
	public function getDataTable_editUsers()
	{
		$userRow_hashPassword = function(&$row)
		{
			$row['password_hash'] = \password_hash($row['password_hash'], PASSWORD_BCRYPT);
			return true;
		};
		
		$formatPassField = function($text, $action)
		{
			if($action == 'show')
				$text = '<span style="font-size:50%;">' . $text . '</span>';
			if($action == 'add')
				$text = str_replace('placeholder="password_hash"', 'placeholder="password"', $text);
			return $text;
		};
			
		$fields = array();
		$fields[] = new DataTableField('id', NULL, NULL, true, false, false);
		$fields[] = new DataTableField('username', NULL, NULL, true, true, true);
		$fields[] = new DataTableField('email', NULL, NULL, true, true, true, array('type' => 'email'));
		$fields[] = new DataTableField('email_verified', NULL, NULL, true, false, false);
		$fields[] = new DataTableField('password_hash', NULL, NULL, true, false, true, array(), $formatPassField);
		$fields[] = new DataTableField('enabled', NULL, NULL, true, true, true);
		$fields[] = new DataTableField('last_active', NULL, NULL, true, false, NULL);
		$events = new DataTableEventCallbacks(NULL, NULL, NULL, $userRow_hashPassword, NULL, NULL);
		return new DataTable('users', 'ki_users', $fields, true, true, false, 50, false, false, false, false, $events, NULL);
	}

	protected static $dataTable_register;
}

?>