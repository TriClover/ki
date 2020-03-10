<?php
namespace mls\ki\Widgets;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Mail;
use \mls\ki\Security\Authenticator;
use \mls\ki\Security\User;
use \mls\ki\Security\Request;
use \PHPMailer\PHPMailer\PHPMailer;

/**
* Provides all of the authentication features in a very small screen footprint.
* Allows non-authenticated users to login, create an account, reset password, and recover username.
* Allows authenticated users to logout.
*/
class LoginForm extends Form
{
	protected $dataTable_register;
	protected $pwForm;
	protected $showRegister;
	
	//Location of editing pages
	protected $profilePath;
	
	/**
	* Sets up the constituent form objects for their separate handling.
	* @param profilePath Used for "Edit Profile" link. Explicity set blank to remove the link.
	* @param sessionPath Used for "Sessions" link. Explicity set blank to remove the link.
	*/
	function __construct(string $profilePath = 'profile.php',
	                     bool   $showRegister= true)
	{
		$this->dataTable_register = LoginForm::getDataTable_register();
		$this->pwForm = new PasswordResetForm();
		$this->profilePath  = $profilePath;
		$this->showRegister = $showRegister;
	}
	
	/**
	* Process form input.
	* Only the password reset + username recovery tab is a unique Form class.
	* Account creation is a highly configured DataTable.
	* The actual login form is just plain HTML with no processing code because
	* its input is handled in the core authentication routine (Authenticator::checkLogin)
	*/
	protected function handleParamsInternal()
	{
		$post = $this->post;
		$get = $this->get;
		
		$this->dataTable_register->handleParams($post, $get);
		$this->pwForm->handleParams($post, $get);
	}
	
	/**
	* Generate the HTML.
	* @return HTML for the entire widget
	*/
	protected function getHTMLInternal()
	{
		$user = Authenticator::$user;
		$request = Authenticator::$request;
		$out = '';
		$outputSpanOpen = '<span style="color:red;font-size:75%;float:right;">';

		if($user === NULL)
		{
			$regForm = $this->dataTable_register->getHTML();
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
			$resetForm = str_replace('.php', '.php#auth_ForgotUsernamePassword', $this->pwForm->getHTML());
			$tabs = array('Login'=>$coreLogin, 'Forgot Username/Password?' => $resetForm);
			if($this->showRegister) $tabs['Register'] = $regForm;
			$tabberHeight = '52px';
			
			$tabberObj = new TargetTabber('auth', $tabs, array('width'=>'350px', 'height'=>'auto'));
			$tabber = $tabberObj->getHTML();
			$out .= $tabber;
		}else{
			$items = array();
			$items[] = new MenuItem($this->getAuthMgmtContents(), '');
			$menuButton = '👤';
			$buttonStyles = array('float'      => 'right',
			                      'height'     => '44px',
								  'width'      => '44px',
								  'font-size'  => '25px',
								  'line-height'=> '44px');
			$userMenu = new Menu($menuButton, $items, $buttonStyles, false);
			$out .= $userMenu->getHTML();
		}
		$sys = $outputSpanOpen . implode(', ', $request->systemMessages) . '</span>';
		$out .= $sys;

		return $out;
	}
	
	protected function getAuthMgmtContents()
	{
		$user = Authenticator::$user;
		
		$actions = [];
		if(!empty($this->profilePath))
			$actions[] = '<a href="' . $this->profilePath . '">Profile</a>';
		$actions[] = '<form method="post" style="display:inline;"><input type="hidden" name="logout" value="1"/>'
			. '<input type="submit" value="Logout" style="display:inline;"/></form>';
		
		$dContents = '';
		$dContents .= '<h1 style="margin-top:0;">' . $user->username . '</h1>';
		$dContents .= implode(' &nbsp; - &nbsp; ', $actions);
		
		return $dContents;
	}
	
	/**
	* @return a DataTable configured for letting non-authenticated users create their own accounts
	*/
	protected static function getDataTable_register()
	{
		$emailIdOfNewUser = NULL;
		$reg_beforeAdd = function(&$row) use(&$emailIdOfNewUser)
		{
			$db = Database::db();
			$site = Config::get()['general']['sitename'];
			
			//Checks for which the error can be shown on-page instead of via email because there is no security implication
			if($row['ki_users.password_hash'] != $_POST['confirmRegPassword'])
			{
				return 'Password and confirmation did not match.';
			}
			if(!isset($_POST['regEmail'])
				|| filter_var($_POST['regEmail'], FILTER_VALIDATE_EMAIL) === false)
			{
				return 'Invalid email address.';
			}
			$email = $_POST['regEmail'];
			
			//turn the password into a hash before storing
			$row['ki_users.password_hash'] = \password_hash($row['ki_users.password_hash'], PASSWORD_BCRYPT);

			//Only failure paths will result in a mail being sent but we can set one up here to avoid repetition
			$mail = new PHPMailer;
			$mail->SetFrom('noreply@'.$_SERVER['SERVER_NAME'], $site.' Account Management');
			$mail->addAddress($email);

			/* Check for duplicate username
			 instead of letting the dataTable show duplicate error on username field
			 This way we don't reveal valid usernames here, they have to check their email
			*/
			$resUname = $db->query('SELECT `id` FROM `ki_users` WHERE `username`=? LIMIT 1',
				array($row['ki_users.username']), 'checking for duplicate username');
			if($resUname === false) return Authenticator::msg_databaseError;
			if(!empty($resUname))
			{
				$mail->Subject = $site . ' Account Creation';
				$mail->Body = 'You tried to register an account with the username "' . $row['ki_users.username']
					. '", but that name is already taken. Please try another.';
				Mail::send($mail);
				return Authenticator::msg_AccountReg;
			}
			
			//get id of email address
			$checkEmailQuery = 'SELECT `id` FROM `ki_emailAddresses` WHERE `emailAddress`=? LIMIT 1';
			$checkEmailRes = $db->query($checkEmailQuery, [$email], 'checking if email address of new user is already tracked');
			if($checkEmailRes === false) return Authenticator::msg_databaseError;
			if(empty($checkEmailRes))
			{
				$addEmailQuery = 'INSERT INTO `ki_emailAddresses` SET `emailAddress`=?,`added`=NOW(),`firstVerified`=NULL,`lastMailSent`=NULL';
				$addEmailRes = $db->query($addEmailQuery, [$email], 'saving email of new user');
				if($addEmailRes === false) return Authenticator::msg_databaseError;
				$emailIdOfNewUser = $db->connection->insert_id;
			}else{
				$emailIdOfNewUser = $checkEmailRes[0]['id'];
			}

			$row['ki_users.defaultEmailAddress'] = NULL;

			return true;
		};
		
		$reg_onAdd = function($userPKs) use(&$emailIdOfNewUser)
		{
			$db = \mls\ki\Database::db();
			$site = \mls\ki\Config::get()['general']['sitename'];
			$from = 'noreply@' . $_SERVER['SERVER_NAME'];
			
			$attachEmailQuery = 'INSERT INTO `ki_emailAddressesOfUser` SET `user`=?,`emailAddress`=?,`associated`=NOW(),`verified`=NULL';
			$attachEmailRes = $db->query($attachEmailQuery, [$userPKs['id'], $emailIdOfNewUser], 'associating email address to user');
			if($attachEmailRes !== false)
			{
				$associationId = $db->connection->insert_id;
				$setDefaultMailQuery = 'UPDATE `ki_users` SET `defaultEmailAddress`=? WHERE `id`=? LIMIT 1';
				$setDefaultMailRes = $db->query($setDefaultMailQuery, [$associationId, $userPKs['id']], 'setting default email address of user');
			}
			
			$newUser = User::loadFromId($userPKs['id']);
			$newUser->sendEmailConfirmation();
			return \mls\ki\Security\Authenticator::msg_AccountReg;
		};
		
		$formatPassField = function($text, $action, &$row)
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
		
		$formatEmailField = function($text, $action, &$row)
		{
			if($action == 'add')
			{
				$text = '<span id="emailTwins">' . $text . ' <input type="email" name="regEmail" id="regEmail" placeholder="email address" required /></span>';
				$text .= '<style>#emailTwins input:first-child{visibility:hidden;position:absolute;}</style>';
			}
			return $text;
		};
		
		$passwordConstraints = LoginForm::getPasswordInputConstraints();
		
		$fields = array();
		$fields[] = new DataTableField('id',                 'ki_users', 'Register', false, false, false);
		$fields[] = new DataTableField('username',           'ki_users', 'Username', true,  false, true);
		$fields[] = new DataTableField('defaultEmailAddress','ki_users', 'Email',    true,  false, true, [], $formatEmailField, -1);
		$fields[] = new DataTableField('password_hash',      'ki_users', 'password', true,  false, true, $passwordConstraints, $formatPassField);
		$fields[] = new DataTableField('enabled',            'ki_users', NULL,       false, false, 1);
		$fields[] = new DataTableField('last_active',        'ki_users', NULL,       false, false, false);
		$events = new DataTableEventCallbacks($reg_onAdd, NULL, NULL, $reg_beforeAdd, NULL, NULL);
		return new DataTable('register', 'ki_users', $fields, true, false, false, 0, false, false, false, false, $events);
	}
	
	public static function getPasswordEditor()
	{
		$user = Authenticator::$user;
		$passwordConstraints = LoginForm::getPasswordInputConstraints();
		
		$formatPassField = function($text, $action, &$row)
		{
			if($action == 'edit')
			{
				$text = preg_replace('/input value=.* name/', 'input value="" id="editPassword" name', $text);
				$text = '<span id="passwordGroup"><input type="password" id="oldPassword" name="oldPassword" placeholder="Old Password"/>' . $text . ' <input type="password" name="confirmEditPassword" id="confirmEditPassword" placeholder="Confirm New Password" style="margin-left:6px;"/></span>';
				$text .= '<script>
					$("#passwordGroup").closest("form").submit(function(event)
					{
						if($("#confirmEditPassword").val() != $("#editPassword").val())
						{
							alert("Password and confirmation must match.");
							return false;
						}
					});</script>';
			}
			return $text;
		};
		
		$beforeEdit = function(&$row)
		{
			$db = Database::db();
			$user = Authenticator::$user;
			
			if($row['ki_users.password_hash'] != $_POST['confirmEditPassword'])
			{
				return 'Password and confirmation did not match.';
			}
			
			if(!\password_verify($_POST['oldPassword'], $user->password_hash))
			{
				return 'Incorrect Old Password.';
			}

			//turn the password into a hash before storing
			$row['ki_users.password_hash'] = \password_hash($row['ki_users.password_hash'], PASSWORD_BCRYPT);

			return true;
		};
		
		$events = new DataTableEventCallbacks(NULL, NULL, NULL, NULL, $beforeEdit, NULL);
		
		$fields = [];
		$fields[] = new DataTableField('password_hash', 'ki_users','Change Password', true, true, false,$passwordConstraints,$formatPassField,1,false);
		$fields[] = new DataTableField(NULL,'ki_users','',false,false,false,[],NULL,1,false);
		$filter = '`id`=' . $user->id;
		$dt = new DataTable('profilePassword','ki_users',$fields,false,false,$filter,1,false,false,false,false,$events,NULL);
		return $dt;
	}
	
	public static function getEmailEditor(int $userId = NULL)
	{
		if($userId === NULL) $userId = Authenticator::$user->id;
		
		//beforeadd: add address to table before the datatable tries to FK reference it
		$newEmailId = NULL;
		$newEmailAddr = NULL;
		$beforeAdd = function(&$row) use($userId, &$newEmailId, &$newEmailAddr)
		{
			$email = $row['ki_emailAddresses.emailAddress'];
			$db = Database::db();
			$checkEmailQuery = 'SELECT `id` FROM `ki_emailAddresses` WHERE `emailAddress`=? LIMIT 1';
			$checkEmailRes = $db->query($checkEmailQuery, [$email], 'checking if address exists');
			if($checkEmailRes === false) return 'Database error checking address.';
			if(empty($checkEmailRes))
			{
				$addEmailQuery = 'INSERT INTO `ki_emailAddresses` SET `emailAddress`=?,`added`=NOW()';
				$addEmailRes = $db->query($addEmailQuery, [$email], 'adding email address to database');
				if($addEmailRes === false) return 'Database error adding address.';
				$newEmailId = $db->connection->insert_id;
			}else{
				$newEmailId = $checkEmailRes[0]['id'];
			}
			$row['ki_emailAddressesOfUser.emailAddress'] = $newEmailId;
			$newEmailAddr = $email;
			
			return true;
		};
		
		//onadd: send confirmation email, set default if there is none
		$onAdd = function($pk) use($userId, &$newEmailId, &$newEmailAddr)
		{
			$db = Database::db();
			$user = User::loadFromId($userId);
			$mailObj = new Mail($newEmailId, $newEmailAddr, 'NOW()');
			$user->sendEmailConfirmation($mailObj);
			
			if(!$user->hasVerifiedEmail())
			{
				$verified = $user->getVerifiedEmails();
				$updateQuery = 'UPDATE `ki_users` SET `defaultEmailAddress`=? WHERE `id`=? LIMIT 1';
				if(empty($verified))
				{
					$db->query($updateQuery, [$pk['id'], $userId], 'setting new mail to default since there was no default');
				}else{
					$db->query($updateQuery, [$verified[0]->associationId, $userId], 'setting first verified mail to default since there was no default');
				}
			}
		};
		
		//beforedelete: block if trying to delete user's primary email
		$beforeDelete = function($pk)
		{
			$db = Database::db();
			$associationId = $pk['ki_emailAddressesOfUser.id'];
			$checkQuery = 'SELECT `ki_users`.`defaultEmailAddress` AS def FROM `ki_emailAddressesOfUser` LEFT JOIN `ki_users` ON `ki_emailAddressesOfUser`.`user`=`ki_users`.`id` WHERE `ki_emailAddressesOfUser`.`id`=? LIMIT 1';
			$checkRes = $db->query($checkQuery, [$associationId], 'checking default email address');
			if($checkRes === false || empty($checkRes)) return 'Database error.';
			if($checkRes[0]['def'] == $associationId) return "Can't delete the default email address. Make another address the default before deleting this one.";
			return true;
		};
		
		$verifiedButtons = [new CallbackButton('Resend',
			function($pk) use($userId)
			{
				$assocationId = $pk['id'];
				$user = User::loadFromId($userId);
				foreach($user->getUnverifiedEmails() as $index => $mailObj)
				{
					if($mailObj->associationId == $assocationId)
					{
						$user->sendEmailConfirmation($mailObj);
						return 'Verification mail re-sent.';
					}
				}
				return 'Error finding email address.';
			},
			function($row)
			{
				foreach($row as $col => $val)
				{
					if(strpos($col,'verified') !== false)
					{
						if($val == 'Yes') return false;
						return true;
					}
				}
				return true;
			}
		)];
		
		$defaultButtons = [new CallbackButton('Make Default',
			function($pk)
			{
				$assocationId = $pk['id'];
				$db = Database::db();
				$setQuery = 'UPDATE `ki_users` SET `defaultEmailAddress`=? WHERE `id`=(SELECT `user` FROM `ki_emailAddressesOfUser` WHERE `id`=?)';
				$db->query($setQuery, [$assocationId, $assocationId], 'setting default to requested value');
			},
			function($row)
			{
				foreach($row as $col => $val)
				{
					if(strpos($col,'default') !== false)
					{
						$verified = false;
						foreach($row as $col => $verVal)
						{
							if(strpos($col,'verified') !== false)
							{
								if($verVal == 'Yes') $verified = true;
								else $verified = false;
								break;
							}
						}
						$isDefault = $val == 'Yes';
						if($isDefault || !$verified) return false;
						return true;
					}
				}
				return true;
			}
		)];
		
		$events = new DataTableEventCallbacks($onAdd, NULL, NULL, $beforeAdd, NULL, $beforeDelete);
		
		$formatEmail = function($cell, $type, &$row) use(&$userId)
		{
			if(isset($_POST['editEmail']) || isset($_GET[ 'editEmail']))
				$cell .= '<input type="hidden" name="editEmail" value="' . $userId . '"/>';
			return $cell;
		};

		$headerText = '';
		if(isset($_POST['editEmail']) || isset($_GET[ 'editEmail']))
			$headerText = '<h1>Editing emails of user ' . $userId . '</h1><a href="?">Back to User Administration</a><br/><br/>';
		
		$fields = [];
		$defaultEmailQuery = 'IF(`ki_emailAddressesOfUser`.`id`=(SELECT `defaultEmailAddress` FROM `ki_users` WHERE `ki_users`.`id`=`ki_emailAddressesOfUser`.`user`),"Yes","")';
		$verifiedQuery = 'IF(`ki_emailAddressesOfUser`.`verified` IS NOT NULL,"Yes","No")';
		
		$fields[] = new DataTableField('emailAddress','ki_emailAddresses',      'Email',         true, false,true,['type'=>'email'], $formatEmail, 0,false);
		$fields[] = new DataTableField('user',        'ki_emailAddressesOfUser','User',          false,false,$userId);
		$fields[] = new DataTableField('associated',  'ki_emailAddressesOfUser','Added',         true, false,false);
		$fields[] = new DataTableField($verifiedQuery,    '','Verified?',true, false,NULL,  [], NULL, 0, false, $verifiedButtons);
		$fields[] = new DataTableField($defaultEmailQuery,'','Default',  true, false,false, [], NULL, 0, false, $defaultButtons);
		$fields[] = new DataTableField(NULL,'ki_emailAddressesOfUser','',false,false,false,[],NULL,0,false);
		
		$filter = '`ki_emailAddressesOfUser`.`user`=' . $userId;
		$dt = new DataTable('profileEmail',['ki_emailAddressesOfUser','ki_emailAddresses'],$fields,true,true,$filter,50,false,false,false,false,$events,NULL,$headerText);
		return $dt;
	}
	
	public static function getPasswordInputConstraints()
	{
		$config = \mls\ki\Config::get()['policies'];
		$passwordConstraints = array('pattern' => $config['passwordRegex'],
							 'title' => $config['passwordRegexDescription'],
							 'type' => 'password',
							 'placeholder' => 'New Password');
		return $passwordConstraints;
	}
	
	public static function getSessionEditor()
	{
		$user = Authenticator::$user;
		$config = Config::get()['sessions'];
		$absolute_seconds = $config['remembered_timeout_absolute_hours'] * 60 * 60;
		$absolute_seconds_temp = $config['temp_timeout_absolute_hours'] * 60 * 60;
		$idle_seconds = $config['remembered_timeout_idle_minutes'] * 60;
		$idle_seconds_temp = $config['temp_timeout_idle_minutes'] * 60;
		$now = time();
		$earliestValidEstablished     = $now - $absolute_seconds;
		$earliestValidEstablishedTemp = $now - $absolute_seconds_temp;
		$earliestValidLastActive      = $now - $idle_seconds;
		$earliestValidLastActiveTemp  = $now - $idle_seconds_temp;
		$timeClause = '(`remember`=1 AND UNIX_TIMESTAMP(`established`)>'.$earliestValidEstablished.' AND UNIX_TIMESTAMP(`last_active`)>'.$earliestValidLastActive.')'
			. ' OR (`remember`=0 AND UNIX_TIMESTAMP(`established`)>'.$earliestValidEstablishedTemp.' AND UNIX_TIMESTAMP(`last_active`)>'.$earliestValidLastActiveTemp.')';
		
		$formatLongField = function($text, $action, &$row)
		{
			if($action == 'show')
			{
				$current = false;
				if(trim($text) == Authenticator::$session->id_hash) $current = true;
				$text = '<div style="font-size:75%;width:15em;word-wrap:break-word;">' . $text;
				if($current) $text .= '<br/>(Current session)';
				$text .= '</div>';
			}
			return $text;
		};
		
		$beforeDelete = function($pk)
		{
			$db = Database::db();
			$copyQuery = 'INSERT INTO `ki_sessionsArchive` '
				. 'SELECT `id_hash`,`user`,`ip`,`fingerprint`,`established`,`last_active`,`remember`,`last_id_reissue`,'
				. '? AS fate, NOW() AS whenArchived '
				. 'FROM `ki_sessions` WHERE `id_hash`=? LIMIT 1';
			$db->query($copyQuery, ['deleted', $pk['ki_sessions.id_hash']], 'archiving session before it is deleted (manually)');
			return true;
		};
		
		$fields = [];
		$fields[] = new DataTableField('id_hash',        'ki_sessions','Hashed ID',      true, false,false,[], $formatLongField, 1,false);
		$fields[] = new DataTableField('user',           'ki_sessions','User ID',        false,false,false,[], NULL, 1,false);
		$fields[] = new DataTableField('ip',             'ki_sessions','IP id',          false,false,false,[], NULL, 1,false);
		$fields[] = new DataTableField('INET6_NTOA(`ki_IPs`.`ip`)','', 'Address',        true, false,false,[], NULL, 1,false);
		$fields[] = new DataTableField('fingerprint',    'ki_sessions','Fingerprint',    true, false,false,[], $formatLongField, 1,false);
		$fields[] = new DataTableField('established',    'ki_sessions','Established',    true, false,false,[], NULL, 1,false);
		$fields[] = new DataTableField('last_active',    'ki_sessions','Last Active',    true, false,false,[], NULL, 1,false);
		$fields[] = new DataTableField('remember',       'ki_sessions','Remember?',      true, false,false,[], NULL, 1,false);
		$fields[] = new DataTableField('last_id_reissue','ki_sessions','Last ID Reissue',true, false,false,[], NULL, 1,false);
		$fields[] = new DataTableField(NULL,'ki_sessions','',false,false,false,[],NULL,1,false);
		$filter = '`user`=' . $user->id . ' AND (' . $timeClause . ')';
		$events = new DataTableEventCallbacks(NULL, NULL, NULL, NULL, NULL, $beforeDelete);
		$dt = new DataTable('sessions',['ki_sessions','ki_IPs'],$fields,false,true,$filter,100,false,false,false,false,$events,NULL);
		return $dt;
	}
}

?>