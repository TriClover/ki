<?php
namespace mls\ki\Security;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Log;
use \mls\ki\Mail;
use \mls\ki\Util;
use \mls\ki\Widgets\DataTable;
use \mls\ki\Widgets\DataTableField;
use \mls\ki\Widgets\DataTableEventCallbacks;
use \PHPMailer\PHPMailer\PHPMailer;

class Authenticator
{
	//Shown for all signup attempts, successful or not
	const msg_AccountReg = 'Check your email for the link to finish creating your account.';
	const msg_AccountVerifyInstruction = 'Finish creating your account by clicking the following link to verify your email address:';
	//ensure that all auth related DB error messages are the same to avoid leaking state information
	const msg_databaseError = 'Database error.';
	const msg_maxAttemptsError = 'Too many attempts.';
	const msg_userDisabledError = 'Your account is currently disabled and can only be re-enabled by staff.';
	const msg_badCredsError = 'Invalid credentials.';
	
	//Instances of the User, Session, and Request classes describing the current request, populated by checkLogin
	public static $user    = NULL;
	public static $session = NULL;
	public static $request = NULL;

	/**
	* Checks session status and fills the user/session/request globals with the info that pages need
	* to check auth and/or track the user.
	* Also check session expiry, SID reissue threshold, account lockout, etc., update last_active, and so on
	*
	* @param params Array of parameters to inject. See "Set defaults" section for valid keys
	* @return false on errors so serious even an anonymous session couldn't be attached, true otherwise.
	*/
	public static function checkLogin(array $params = array())
	{
		//set defaults
		//Priority: 1) Injected values 2) POST data by default names 3) hardcoded defaults signifying no action
		$username = NULL;
		$password = NULL;
		$remember = true;
		$logout   = false;
		$sid      = NULL;
		if(isset($params['username'])) $username = $params['username']; elseif(isset($_POST['login_username']) && !empty($_POST['login_username'])) $username = $_POST['login_username'];
		if(isset($params['password'])) $password = $params['password']; elseif(isset($_POST['login_password']) && !empty($_POST['login_password'])) $password = $_POST['login_password'];
		if(isset($params['remember'])) $remember = $params['remember']; elseif(isset($_POST['login_remember']) && !empty($_POST['login_remember'])) $remember = $_POST['login_remember'];
		if(isset($params['logout'  ])) $logout   = $params['logout'  ]; elseif(isset($_POST['logout'        ]) && !empty($_POST['logout'        ])) $logout   = true;
		if(isset($params['sid'     ])) $sid      = $params['sid'     ]; elseif(isset($_COOKIE['id'          ]) && !empty($_COOKIE['id'          ])) $sid      = $_COOKIE['id'          ];
		
		//setup
		$db = Database::db();
		$db->connection->begin_transaction();
		$now = time();
		
		//config
		$config = Config::get();
		$pwWindowBegin       = $now - ($config['limits']['passwordAttemptWindow_minutes'] * 60);
		$pwMax               =         $config['limits']['maxPasswordAttempts'          ];
		$pwLockoutUntil      = $now + ($config['limits']['lockout_minutes'              ] * 60);
		$accountsWindowBegin = $now - ($config['limits']['accountAttemptWindow_minutes' ] * 60);
		$accMax              =         $config['limits']['maxAccountAttempts'           ];
		$ipBlockUntil        = $now + ($config['limits']['ipBlock_minutes'              ] * 60);
		
		//gather HTTP request related data
		$request = new Request();
		if($request->ipId === NULL || $request->ipBlocked === NULL) {Session::deleteCookie(); $db->connection->commit(); $db->connection->autocommit(true); return false;}
		
		//check input: session ID
		$providedSid = false;
		$sidConfirmedBad = false;
		$sidConfirmedGood = false;
		$session = NULL;
		if($sid !== NULL)
		{
			$providedSid = true;
			$sessionAttempt = Session::load($sid, $request);
			if($sessionAttempt === NULL)
			{
				$sidConfirmedBad = true;
			}
			elseif($sessionAttempt instanceof Session)
			{
				$sidConfirmedGood = true;
				$session = $sessionAttempt;
			}
		}
		
		//check input: login attempt
		$providedCreds = false;
		$credsConfirmedGood = false;
		$credsConfirmedBad = false;
		$user = NULL;
		$trustedIp = false;
		if($username !== NULL && $password !== NULL)
		{
			$providedCreds = true;
			$userAttempt = User::loadFromCreds($username, $password, $request);
			if($userAttempt === NULL)
			{
				$credsConfirmedBad = true;
			}
			elseif($userAttempt instanceof User)
			{
				$credsConfirmedGood = true;
				$user = $userAttempt;
				if($username == 'root')
				{
					$trustedIp = true;
				}else{
					$trustedIp = $user->isTrustedIp($request->ipId);
				}
			}
		}

		//record failures that will apply toward limits, then apply limits if necessary
		$badAccountsFromIp = 0;
		if($sidConfirmedBad)
		{
			$db->query('INSERT INTO `ki_failedSessions` SET `input`=?,`ip`=?,`when`=NOW()',
				array($sid, $request->ipId), 'recording failed session attempt');
			$badSessions = $db->query('SELECT COUNT(DISTINCT `input`) AS total FROM `ki_failedSessions` WHERE `ip`=? AND `when`>FROM_UNIXTIME(?)',
				array($request->ipId, $accountsWindowBegin), 'checking distinct bad session IDs from IP');
			if($badSessions !== false) $badAccountsFromIp += $badSessions[0]['total'];
		}
		if($credsConfirmedBad)
		{
			$db->query('INSERT INTO `ki_failedLogins` SET `inputUsername`=?,`ip`=?,`when`=NOW()',
				array($username, $request->ipId), 'recording failed session attempt');
			$badLogins = $db->query('SELECT COUNT(DISTINCT `inputUsername`) AS total FROM `ki_failedLogins` WHERE `ip`=? AND `when`>FROM_UNIXTIME(?)',
				array($request->ipId, $pwWindowBegin), 'checking total bad logins from IP');
			if($badLogins !== false) $badAccountsFromIp += $badLogins[0]['total'];
		}
		if($badAccountsFromIp > $accMax)
		{
			$db->query('UPDATE `ki_IPs` SET `block_until`=FROM_UNIXTIME(?) WHERE `id`=?',
				array($ipBlockUntil, $request->ipId), 'blocking IP');
			$request->ipBlocked = true;
		}
		
		$accountJustLocked = false;
		$badLoginsForAccount = $db->query('SELECT COUNT(*) AS total FROM `ki_failedLogins` WHERE `inputUsername`=? AND `when`>FROM_UNIXTIME(?)',
			array($username, $pwWindowBegin), 'checking total bad logins for account');
		if($badLoginsForAccount !== false && !empty($badLoginsForAccount) && $badLoginsForAccount[0]['total'] > $pwMax)
		{
			Log::trace('Locking out account');
			$db->query('UPDATE `ki_users` SET `lockout_until`=FROM_UNIXTIME(?) WHERE `username`=? LIMIT 1',
				array($pwLockoutUntil, $username), 'Locking out account for password attempts');
			$accountJustLocked = true;
		}
		$accountLocked = $accountJustLocked || ($user !== NULL && $user->lockout_until !== NULL && $user->lockout_until >= $now);
		
		//Decide what to do overall.
		$ret = true;
		if($request->ipBlocked)
		{
			//Delete any existing session and leave the user without any session.
			if($sidConfirmedGood) Session::deleteSession($session->id_hash, 'ip_block');
			Session::deleteCookie();
			$ret = false;
			$request->systemMessages[] = Authenticator::msg_maxAttemptsError;
		}else{
			//check for email confirmation nonce
			$providedNonce = false;
			$nonce = NULL;
			$nonceCarriesInstantLogin = false;
			if(isset($_GET['ki_spn_ec']) && !empty($_GET['ki_spn_ec'])) $providedNonce = $_GET['ki_spn_ec'];
			if(isset($_POST['ki_spn_ec']) && !empty($_POST['ki_spn_ec'])) $providedNonce = $_POST['ki_spn_ec'];
			if($providedNonce !== false)
			{
				$nonce = Nonce::load($providedNonce, $request);
				if($nonce instanceof Nonce)
				{
					if($nonce->purpose == 'email_verify')
					{
						$nonceCarriesInstantLogin = true;
						if($nonce->emailNewlyVerified)
							$request->systemMessages[] = 'Email verified.';
					}
				}else{
					$request->systemMessages[] = $nonce;
				}
			}

			//Process authentication related actions
			if($logout)
			{
				//Delete any existing session and give the user a new anonymous session.
				if($sidConfirmedGood) Session::deleteSession($session->id_hash, 'logout');
				$ret = Authenticator::giveNewAnonymousSession($request);
			}
			elseif($nonceCarriesInstantLogin)
			{
				$newUser = User::loadFromId($nonce->user);
				if(!$newUser->enabled)
				{
					$request->systemMessages[] = Authenticator::msg_userDisabledError;
					if($sidConfirmedGood)
					{
						$session->attach();
					}else{
						$ret = Authenticator::giveNewAnonymousSession($request);
					}
				}else{
					if($sidConfirmedGood)
					{
						if($session->user === NULL)
						{
							//promote existing anon session to user session
							$newSession = $session->promoteAttach($nonce->user, $remember);
							if($newSession === false)
							{
								//on failure to promote, just delete existing session and make new one
								Session::deleteSession($session->id_hash, 'relogin');
								$newSession = Session::create($nonce->user, false, $request);
								$newSession->attach();
							}
						}else{
							//delete existing session and make new one
							Session::deleteSession($session->id_hash, 'relogin');
							$newSession = Session::create($nonce->user, false, $request);
							$newSession->attach();
						}
					}else{
						//make new session
						$newSession = Session::create($nonce->user, false, $request);
						$newSession->attach();
					}
				}
			}
			elseif($accountLocked && $providedCreds)
			{
				$request->systemMessages[] = Authenticator::msg_maxAttemptsError;
				if($sidConfirmedGood)
				{
					$session->attach();
				}else{
					$ret = Authenticator::giveNewAnonymousSession($request);
				}
			}
			elseif($credsConfirmedGood)
			{
				//clear fail log
				$db->query('DELETE FROM `ki_failedLogins` WHERE `inputUsername`=?', array($username),
					'clearing user login fail log on succesful auth');

				//if user account is not loginable, keep existing session (if no session or session expired make anon one)
				if(!$user->enabled)
				{
					$request->systemMessages[] = Authenticator::msg_userDisabledError;
					if($sidConfirmedGood)
					{
						$session->attach();
					}else{
						$ret = Authenticator::giveNewAnonymousSession($request);
					}
				}
				elseif(!$user->hasVerifiedEmail() && $username != 'root')
				{
					//Resend email confirmation email for this user
					$request->systemMessages[] = Authenticator::msg_AccountReg;
					$user->sendEmailConfirmation();
					if($sidConfirmedGood)
					{
						$session->attach();
					}else{
						$ret = Authenticator::giveNewAnonymousSession($request);
					}
				}
				elseif($trustedIp === false)
				{
					//Do email nonce for logging in from new IP
					//todo:let user select which email instead of default
					$request->systemMessages[] = 'We detect you are attempting to log in from a new location. A confirmation email has been sent to your default email address containing a link you can use to login.';
					$user->sendEmailNonceForNewLocation($request);
					if($sidConfirmedGood)
					{
						$session->attach();
					}else{
						$ret = Authenticator::giveNewAnonymousSession($request);
					}
				}else{
					if($trustedIp === NULL)
					{
						Log::error('Could not determine if IP was trusted so let them in anyway');
					}
					
					if($sidConfirmedGood)
					{
						if($session->user === NULL)
						{
							//promote existing anon session to user session
							$newSession = $session->promoteAttach($user->id, $remember);
							if($newSession === false)
							{
								//on failure to promote, still attach existing session
								$session->attach();
								$request->systemMessages[] = Authenticator::msg_databaseError;
							}
						}else{
							//destroy existing user-session and make new session for user
							Session::deleteSession($session->id_hash, 'relogin');
							$newSession = Session::create($user->id, $remember, $request);
							$newSession->attach();
						}
					}else{
						//make new session for user
						$newSession = Session::create($user->id, $remember, $request);
						$newSession->attach();
					}
				}
			}
			elseif($sidConfirmedGood)
			{
				$session->attach();
				if($credsConfirmedBad) $request->systemMessages[] = Authenticator::msg_badCredsError;
			}else{
				//last resort if no other conditions are met (probably first time visitor): make new anon session
				$ret = Authenticator::giveNewAnonymousSession($request);
				if($credsConfirmedBad) $request->systemMessages[] = Authenticator::msg_badCredsError;
			}
		}
		
		Authenticator::$request = $request;
		
		$db->connection->commit(); $db->connection->autocommit(true);
		return $ret;
	}
	
	/**
	* Creates session in the database, creates the Session object,
	* sets the global session to the object, sends the cookie for the session
	* (deletes any session cookie on failure)
	* @param the request to operate within
	* @return boolean for success
	*/
	protected static function giveNewAnonymousSession(Request $requestContext)
	{
		$ret = true;
		$newSession = Session::create(NULL, true, $requestContext);
		if($newSession !== false)
		{
			$newSession->attach();
		}else{
			Session::deleteCookie();
			$ret = false;
		}
		return $ret;
	}
	
	/*
	* Performs a cryptographically secure hash with a computational factor
	* good enough for passwords. Actual password hashing should use PHP's builtin
	* bcrypt-powered function instead. This function is for cases where that
	* won't work well, such as hashing the session ID.
	*/
	public static function pHash($input, $salt='dkljbhf3948go07hf7g578')
	{
		// output length 128
		return hash_pbkdf2('whirlpool', $input, $salt, 1000);
	}
}

?>