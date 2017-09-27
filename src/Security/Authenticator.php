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
use \mls\ki\Widgets\Menu;
use \mls\ki\Widgets\MenuItem;
use \mls\ki\Widgets\TargetTabber;
use \mls\ki\Widgets\RadTabber;
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
			if($sidConfirmedGood) Authenticator::deleteSession($session->id_hash);
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
				if($nonce instanceof Nonce && $nonce->purpose == 'email_verify')
				{
					$request->systemMessages[] = 'Email verified.';
					$db->query('UPDATE `ki_users` SET `email_verified`=1 WHERE `id`=? LIMIT 1',
						array($nonce->user), 'setting email verification flag to TRUE for user');
					$nonceCarriesInstantLogin = true;
				}else{
					$request->systemMessages[] = $nonce;
				}
			}

			//Process authentication related actions
			if($logout)
			{
				//Delete any existing session and give the user a new anonymous session.
				if($sidConfirmedGood) Authenticator::deleteSession($session->id_hash);
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
								Authenticator::deleteSession($session->id_hash);
								$newSession = Session::create($nonce->user, false, $request);
								$newSession->attach();
							}
						}else{
							//delete existing session and make new one
							Authenticator::deleteSession($session->id_hash);
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
				elseif(!$user->email_verified)
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
				}else{
					if($sidConfirmedGood)
					{
						if($session->user === NULL && !$session->expired)
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
							Authenticator::deleteSession($session->id_hash);
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
				if(!$session->expired)
				{
					//Attach loaded session
					$session->attach();
				}else{
					//Delete session and make new anonymous session
					Authenticator::deleteSession($session->id_hash);
					$ret = Authenticator::giveNewAnonymousSession($request);
				}
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
	
	/**
	* Deletes a session from the DB
	* @param sidHash the sid_hash to look for
	*/
	protected static function deleteSession(string $sidHash)
	{
		$db = Database::db();
		$db->query('DELETE FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1',
			array($sidHash), 'deleting session');
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
		
		$outputSpanOpen = '<span style="color:red;font-size:75%;float:right;">';
		
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
				. '</form>';
			$resetForm = str_replace('.php', '.php#auth_ForgotUsernamePassword', Authenticator::getMarkup_resetForm());
			$tabs = array('Login'=>$coreLogin, 'Register'=>$regForm, 'Forgot Username/Password?' => $resetForm);
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
			$menuButton = '👤 ' . $user->username . '<br/>';
			$out .= Menu::getHTML($menuButton, $items, array('float'=>'right', 'height'=>'34px'));
		}
		$sys = $outputSpanOpen . implode(', ', $request->systemMessages) . '</span>';
		$out .= $sys;

		return $out;
	}
	
	public static function getMarkup_resetForm()
	{
		Log::trace('Getting markup for password reset form');
		$config = Config::get()['policies'];
		$db = Database::db();
		
		$pwNonce = NULL;
		if(isset($_GET['ki_spn_pw']))
		{
			$pwNonce = Nonce::load($_GET['ki_spn_pw']);
		}
		elseif(isset($_POST['ki_spn_pw'])){
			$pwNonce = Nonce::load($_POST['ki_spn_pw']);
		}

		if(isset($_POST['forgot']) && isset($_POST['email']))
		{
			Log::trace('Processing initial password reset request');
			$site = Config::get()['general']['sitename'];
			$from = 'noreply@' . $_SERVER['SERVER_NAME'];
			$mail = new PHPMailer;
			$mail->From = $from;
			$mail->FromName = $site . ' Account Maintenance';
			$mail->addAddress($_POST['email']);
			
			if(isset($_POST['username'])) //password reset
			{
				$userData = $db->query('SELECT `id`,`email_verified` FROM `ki_users` WHERE `email`=? AND `username`=?',
					array($_POST['email'], $_POST['username']),
					'getting id of user');
				if($userData === false) return "Database error.";
				if(!empty($userData))
				{
					$userid = $userData[0]['id'];
					$emailVerified = $userData[0]['email_verified'];
					//if email not verified, send verification mail instead
					if(!$emailVerified)
					{
						$userObj = User::loadFromId($userid);
						$userObj->sendEmailConfirmation();
					}else{
						$pwNonce = Nonce::create('password_reset', $userid, false, false);
						$link = Util::getUrl() . '?ki_spn_pw=' . $pwNonce->nonceValue . '#auth_ForgotUsernamePassword';
						$mail->Subject = $site . ' Password Reset';
						$mail->Body = 'Reset your password by following this link: ' . "\n" . $link;
						Mail::send($mail);
					}
				}
			}else{                        //username recovery
				$userData = $db->query('SELECT `username` FROM `ki_users` WHERE `email`=?', array($_POST['email']),
					'getting username for user with given email');
				if($userData === false) return "Database error.";
				if(!empty($userData))
				{
					$username = $userData[0]['username'];
					$mail->Subject = $site . ' Username Recovery';
					$mail->Body = 'We recieved a username recovery request for this email address. '
						. "\n" . 'Your username is ' . $username;
					Mail::send($mail);
				}
			}
			return 'Request recieved - check your email.<br/><div style="height:5px;">&nbsp;</div>';
		}
		elseif($pwNonce instanceof Nonce && $pwNonce->purpose == 'password_reset')
		{
			//Email reauthed, show new password form
			return Authenticator::getPasswordResetStage2Form($pwNonce->user);
		}
		elseif(isset($_POST['reset_password']) &&
		       isset($_POST['reset_password_confirm']) &&
			   $pwNonce instanceof Nonce && $pwNonce->purpose == 'password_reset2')
		{
			Log::trace('Processing final step of password reset');
			$preg_result = preg_match('/'.$config['passwordRegex'].'/',$_POST['reset_password']);
			if($preg_result != 1)
			{
				Authenticator::$request->systemMessages[] = "Password must match the pattern: " . $config['passwordRegexDescription'];
				return Authenticator::getPasswordResetStage2Form($pwNonce->user);
			}
			elseif($_POST['reset_password'] != $_POST['reset_password_confirm'])
			{
				Authenticator::$request->systemMessages[] = 'Password and confirmation must match.';
				return Authenticator::getPasswordResetStage2Form($pwNonce->user);
			}else{
				//turn the password into a hash before storing
				$hash = \password_hash($_POST['reset_password'], PASSWORD_BCRYPT);
				$db->query('UPDATE `ki_users` SET `password_hash`=? WHERE `id`=? LIMIT 1',
					array($hash, $pwNonce->user),
					'changing user password from reset form');
				return 'Password changed successfully. Try logging in with it now.';
			}
		}
		Log::trace('Returning markup for initial password reset form');
		$formOpen = '<form method="post" action="' . $_SERVER['SCRIPT_NAME'] . '"><input type="email" name="email" placeholder="email" style="clear:both;" required/>';
		$formClose = '</form>';
		$tabs = array('Forgot Password' => $formOpen . '<input type="text" name="username" placeholder="username" required/><input type="submit" name="forgot" value="Reset"/>' . $formClose, 'Forgot Username' => $formOpen . '<input type="submit" name="forgot" value="Recover"/>' . $formClose);
		return RadTabber::getHTML('reset', $tabs, false, array('height'=>'45px'));
	}
	
	protected static function getPasswordResetStage2Form(int $user)
	{
		$config = Config::get()['policies'];
		$pwNonce = Nonce::create('password_reset2', $user, false, false);
		$form = '<form method="post" id="ki_passwordResetter" action="' . $_SERVER['SCRIPT_NAME']
			. '">Enter a new password here:<br/>'
			. $pwNonce->getHTML('ki_spn_pw')
			. '<input type="password" name="reset_password"         id="reset_password"         required placeholder="New password"         pattern="' . $config['passwordRegex'] . '" title="' . $config['passwordRegexDescription'] . '" />'
			. '<input type="password" name="reset_password_confirm" id="reset_password_confirm" required placeholder="Confirm new password" pattern="' . $config['passwordRegex'] . '" title="' . $config['passwordRegexDescription'] . '" />'
			. '<input type="submit" name="reset_password_submit" value="Submit"/>'
			. '</form>';
		$form .= '<script>
				$("#ki_passwordResetter").submit(function(event)
				{
					if($("#reset_password").val() != $("#reset_password_confirm").val())
					{
						alert("Password and confirmation must match.");
						return false;
					}
				});</script>';
		return $form;
	}
	
	protected static function getDataTable_register()
	{
		$reg_beforeAdd = function(&$row)
		{
			$db = Database::db();
			$site = Config::get()['general']['sitename'];
			
			//Checks for which the error can be shown on-page instead of via email because there is no security implication
			if($row['password_hash'] != $_POST['confirmRegPassword'])
			{
				return 'Password and confirmation did not match.';
			}

			//Only failure paths will result in a mail being sent but we can set one up here to avoid repetition
			$mail = new PHPMailer;
			$mail->From = 'noreply@' . $_SERVER['SERVER_NAME'];
			$mail->FromName = $site . ' Account Management';
			$mail->addAddress($row['email']);
			
			//turn the password into a hash before storing
			$row['password_hash'] = \password_hash($row['password_hash'], PASSWORD_BCRYPT);

			//check for duplicate email: instead of letting the dataTable show duplicate error on mail field, abort.
			$resMail = $db->query('SELECT `id`,`email_verified` FROM `ki_users` WHERE `email`=? LIMIT 1',
				array($row['email']), 'Checking for duplicate email');
			if($resMail === false) return Authenticator::msg_databaseError;
			if(!empty($resMail))
			{
				if($resMail[0]['email_verified'])
				{
					$mail->Subject = $site . ' Account Re-registration';
					$mail->Body = 'It looks like you tried to register an account with this email address, but this email address is already associated with an account. If you are having trouble getting into your account, please use the "Forgot password/username?" feature on the site.';
					Mail::send($mail);
				}else{
					$dupUser = User::loadFromId($resMail[0]['id']);
					$dupUser->sendEmailConfirmation();
				}
				return Authenticator::msg_AccountReg;
			}
			
			//check for duplicate username with same goals as above check for dup. email
			$resUname = $db->query('SELECT `id` FROM `ki_users` WHERE `username`=? LIMIT 1',
				array($row['username']), 'checking for duplicate username');
			if($resUname === false) return Authenticator::msg_databaseError;
			if(!empty($resUname))
			{
				$mail->Subject = $site . ' Account Creation';
				$mail->Body = 'You tried to register an account with the username "' . $row['username']
					. '", but that name is already taken. Please try another.';
				Mail::send($mail);
				return Authenticator::msg_AccountReg;
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
				$newUser = User::loadFromId($userPKs['id']);
				$newUser->sendEmailConfirmation();
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
	public static function getDataTable_editUsers()
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