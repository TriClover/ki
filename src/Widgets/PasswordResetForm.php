<?php
namespace mls\ki\Widgets;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Log;
use \mls\ki\Mail;
use \mls\ki\Security\Authenticator;
use \mls\ki\Security\Nonce;
use \mls\ki\Security\Request;
use \mls\ki\Util;
use \PHPMailer\PHPMailer\PHPMailer;

class PasswordResetForm extends Form
{
	protected $response = NULL;
	protected $user = NULL;
	
	const resInitialForm     = 0;
	const resError           = 1;
	const resRequestRecieved = 2;
	const resStage2Form      = 3;
	const resPasswordChanged = 4;
	
	function __construct()
	{
		
	}
	
	public function handleParams($post = NULL, $get = NULL)
	{
		Log::trace('Handling params for password reset form');
		$config = Config::get()['policies'];
		$db = Database::db();
		
		$this->response = PasswordResetForm::resInitialForm;
		$pwNonce = NULL;
		if(isset($_GET['ki_spn_pw']))
		{
			$pwNonce = Nonce::load($_GET['ki_spn_pw']);
		}
		elseif(isset($_POST['ki_spn_pw']))
		{
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
				if($userData === false)
				{
					$this->response = PasswordResetForm::resError;
				}
				elseif(!empty($userData))
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
					$this->response = PasswordResetForm::resRequestRecieved;
				}else{
					$this->response = PasswordResetForm::resRequestRecieved;
				}
			}else{      //username recovery
				$userData = $db->query('SELECT `username` FROM `ki_users` WHERE `email`=?', array($_POST['email']),
					'getting username for user with given email');
				if($userData === false)
				{
					$this->response = PasswordResetForm::resError;
				}
				elseif(!empty($userData))
				{
					$username = $userData[0]['username'];
					$mail->Subject = $site . ' Username Recovery';
					$mail->Body = 'We recieved a username recovery request for this email address. '
						. "\n" . 'Your username is ' . $username;
					Mail::send($mail);
					$this->response = PasswordResetForm::resRequestRecieved;
				}else{
					$this->response = PasswordResetForm::resRequestRecieved;
				}
			}
		}
		elseif($pwNonce instanceof Nonce && $pwNonce->purpose == 'password_reset')
		{
			//Email reauthed, show new password form
			$this->response = PasswordResetForm::resStage2Form;
			$this->user = $pwNonce->user;
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
				$this->response = PasswordResetForm::resStage2Form;
				$this->user = $pwNonce->user;
			}
			elseif($_POST['reset_password'] != $_POST['reset_password_confirm'])
			{
				Authenticator::$request->systemMessages[] = 'Password and confirmation must match.';
				$this->response = PasswordResetForm::resStage2Form;
				$this->user = $pwNonce->user;
			}else{
				//turn the password into a hash before storing
				$hash = \password_hash($_POST['reset_password'], PASSWORD_BCRYPT);
				$db->query('UPDATE `ki_users` SET `password_hash`=? WHERE `id`=? LIMIT 1',
					array($hash, $pwNonce->user),
					'changing user password from reset form');
				$this->response = PasswordResetForm::resPasswordChanged;
			}
		}
	}
	
	public function getHTML()
	{
		Log::trace('Returning markup for initial password reset form');
		$config = Config::get()['policies'];
		$db = Database::db();
		
		switch($this->response)
		{
			case PasswordResetForm::resError:
			return 'Database error.';
			break;
			
			case PasswordResetForm::resRequestRecieved:
			return 'Request recieved - check your email.<br/><div style="height:5px;">&nbsp;</div>';
			break;
			
			case PasswordResetForm::resStage2Form:
			return PasswordResetForm::getStage2Form($this->user);
			break;
			
			case PasswordResetForm::resPasswordChanged:
			return 'Password changed successfully. Try logging in with it now.';
		}
		//PasswordResetForm::resInitialForm
		return PasswordResetForm::getInitialForm();
	}

	protected static function getInitialForm()
	{
		$formOpen = '<form method="post" action="' . $_SERVER['SCRIPT_NAME'] . '"><input type="email" name="email" placeholder="email" style="clear:both;" required/>';
		$formClose = '</form>';
		$tabs = array('Forgot Password' => $formOpen . '<input type="text" name="username" placeholder="username" required/><input type="submit" name="forgot" value="Reset"/>' . $formClose, 'Forgot Username' => $formOpen . '<input type="submit" name="forgot" value="Recover"/>' . $formClose);
		$forgotTabber = new RadTabber('reset', $tabs, false, array('height'=>'45px'));
		return $forgotTabber->getHTML();
	}
	
	protected static function getStage2Form(int $user)
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
}
?>