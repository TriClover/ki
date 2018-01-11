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
	
	/**
	* Sets up the constituent form objects for their separate handling.
	*/
	function __construct()
	{
		$this->dataTable_register = LoginForm::getDataTable_register();
		$this->pwForm = new PasswordResetForm();
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
			$tabs = array('Login'=>$coreLogin, 'Register'=>$regForm, 'Forgot Username/Password?' => $resetForm);
			$tabberHeight = '52px';
			
			$tabberObj = new TargetTabber('auth', $tabs, array('width'=>'350px', 'height'=>'auto'));
			$tabber = $tabberObj->getHTML();
			$out .= $tabber;
		}else{
			$items = array();
			$items[] = new MenuItem('Logout', $_SERVER['SCRIPT_NAME'], array('logout' => '1'));
			$menuButton = '👤 ' . $user->username . '<br/>';
			$userMenu = new Menu($menuButton, $items, array('float'=>'right', 'height'=>'34px'));
			$out .= $userMenu->getHTML();
		}
		$sys = $outputSpanOpen . implode(', ', $request->systemMessages) . '</span>';
		$out .= $sys;

		return $out;
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
}

?>