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
		$reg_beforeAdd = function(&$row)
		{
			$db = Database::db();
			$site = Config::get()['general']['sitename'];
			
			//Checks for which the error can be shown on-page instead of via email because there is no security implication
			if($row['ki_users.password_hash'] != $_POST['confirmRegPassword'])
			{
				return 'Password and confirmation did not match.';
			}

			//Only failure paths will result in a mail being sent but we can set one up here to avoid repetition
			$mail = new PHPMailer;
			$mail->From = 'noreply@' . $_SERVER['SERVER_NAME'];
			$mail->FromName = $site . ' Account Management';
			$mail->addAddress($row['ki_users.email']);
			
			//turn the password into a hash before storing
			$row['ki_users.password_hash'] = \password_hash($row['ki_users.password_hash'], PASSWORD_BCRYPT);

			//check for duplicate email: instead of letting the dataTable show duplicate error on mail field, abort.
			$resMail = $db->query('SELECT `id`,`email_verified` FROM `ki_users` WHERE `email`=? LIMIT 1',
				array($row['ki_users.email']), 'Checking for duplicate email');
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
		
		$passwordConstraints = LoginForm::getPasswordInputConstraints();
		
		$fields = array();
		$fields[] = new DataTableField('id',            'ki_users', 'Register', false, false, false);
		$fields[] = new DataTableField('username',      'ki_users', 'Username', true, false, true);
		$fields[] = new DataTableField('email',         'ki_users', 'Email', true, false, true, array('type' => 'email'));
		$fields[] = new DataTableField('email_verified','ki_users', NULL, false, false, 0);
		$fields[] = new DataTableField('password_hash', 'ki_users', 'password', true, false, true, $passwordConstraints, $formatPassField);
		$fields[] = new DataTableField('enabled',       'ki_users', NULL, false, false, 1);
		$fields[] = new DataTableField('last_active',   'ki_users', NULL, false, false, false);
		$events = new DataTableEventCallbacks($reg_onAdd, NULL, NULL, $reg_beforeAdd, NULL, NULL);
		return new DataTable('register', 'ki_users', $fields, true, false, false, 0, false, false, false, false, $events);
	}
	
	public static function getPasswordEditor()
	{
		$user = Authenticator::$user;
		$passwordConstraints = LoginForm::getPasswordInputConstraints();
		
		$formatPassField = function($text, $action)
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
	
	public static function getEmailEditor()
	{
		$user = Authenticator::$user;
		
		/*todo: new email address should require verification
		This can't be done until after support for multiple email addresses.
		If done now, might run into the situation where they type it wrong so it
		can't be verified, but now they can't login because their email isn't verified.
		With multiple, they can continue to use the old one until their new email is verified.
		*/
		
		$fields = [];
		$fields[] = new DataTableField('email', 'ki_users','Change Email', true,true, false,['type'=>'email'], NULL, 1,false);
		$fields[] = new DataTableField(NULL,'ki_users','',false,false,false,[],NULL,1,false);
		$filter = '`id`=' . $user->id;
		$dt = new DataTable('profileEmail','ki_users',$fields,false,false,$filter,1,false,false,false,false,NULL,NULL);
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
		
		$formatLongField = function($text, $action)
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