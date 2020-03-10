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

/**
* Provides password reset and username recovery
*/
class PasswordResetForm extends Form
{
	/** Processing code puts its result here for the benefit of the HTML generating code */
	protected $response = NULL;
	/** Processing code puts a userID here for response codes that require HTML generation to be user aware */
	protected $user = NULL;
	
	//these are the possible values of $this->response
	const resInitialForm     = 0;
	const resError           = 1;
	const resRequestRecieved = 2;
	const resStage2Form      = 3;
	const resPasswordChanged = 4;
	
	function __construct()
	{
		
	}
	
	/**
	* Process input from throughout the available procedures, including:
	* The initial request form, the nonce link that goes in an email, and the stage-2 form
	*/
	protected function handleParamsInternal()
	{
		Log::trace('Handling params for password reset form');
		$config = Config::get()['policies'];
		$db = Database::db();
		$post = $this->post;
		$get = $this->get;
		
		$this->response = PasswordResetForm::resInitialForm;
		$pwNonce = NULL;
		if(isset($get['ki_spn_pw']))
		{
			$pwNonce = Nonce::load($get['ki_spn_pw']);
		}
		elseif(isset($post['ki_spn_pw']))
		{
			$pwNonce = Nonce::load($post['ki_spn_pw']);
		}

		if(isset($post['forgot']) && isset($post['email']))
		{
			Log::trace('Processing initial password reset request');
			$site = Config::get()['general']['sitename'];
			$from = 'noreply@' . $_SERVER['SERVER_NAME'];
			$mail = new PHPMailer;
			$mail->SetFrom($from, $site.' Account Maintenance');
			$mail->addAddress($post['email']);
			
			if(isset($post['username'])) //password reset
			{
				//make sure they entered a valid combination of email and username,
				//and check if the email is verified for them
				$checkValidityQuery = <<<'END_SQL'
				SELECT
					`ki_users`.`id`                      AS userid,
					`ki_emailAddresses`.`id`             AS emailid,
					`ki_emailAddressesOfUser`.`id`       AS associationid,
					`ki_emailAddressesOfUser`.`verified` AS verified
				FROM `ki_emailAddressesOfUser`
					LEFT JOIN `ki_users` ON `ki_emailAddressesOfUser`.`user`=`ki_users`.`id`
					LEFT JOIN `ki_emailAddresses` ON `ki_emailAddressesOfUser`.`emailAddress`=`ki_emailAddresses`.`id`
				WHERE `ki_emailAddresses`.`emailAddress`=? AND `ki_users`.`username`=?
END_SQL;
				$userData = $db->query($checkValidityQuery, [$post['email'], $post['username']], 'getting id of user');
				if($userData === false)
				{
					$this->response = PasswordResetForm::resError;
				}
				elseif(!empty($userData))
				{
					$row = $userData[0];
					$userid = $row['userid'];
					$emailVerified = $row['verified'];
					//if email not verified, send verification mail instead
					if(!$emailVerified)
					{
						$mailObj = new Mail($row['mailid'], $post['email'], NULL,NULL,NULL, $row['associationid']);
						$userObj = User::loadFromId($userid);
						$userObj->sendEmailConfirmation($mailObj);
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
				$getNameQuery = <<<'END_SQL'
				SELECT
					`ki_users`.`username` AS username
				FROM `ki_emailAddressesOfUser`
					LEFT JOIN `ki_users` ON `ki_emailAddressesOfUser`.`user`=`ki_users`.`id`
					LEFT JOIN `ki_emailAddresses` ON `ki_emailAddressesOfUser`.`emailAddress`=`ki_emailAddresses`.`id`
				WHERE `ki_emailAddresses`.`emailAddress`=?
END_SQL;
				$userData = $db->query($getNameQuery, [$post['email']], 'getting username for user with given email');
				if($userData === false)
				{
					$this->response = PasswordResetForm::resError;
				}
				elseif(!empty($userData))
				{
					$username = [];
					foreach($userData as $row)
					{
						$username[] = $row['username'];
					}
					$username = implode(",\n", $username);
					$mail->Subject = $site . ' Username Recovery';
					$mail->Body = 'We recieved a username recovery request for this email address. '
						. "\n" . 'Here are all usernames associated with this email address: '
						. "\n" . $username;
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
		elseif(isset($post['reset_password']) &&
		       isset($post['reset_password_confirm']) &&
			   $pwNonce instanceof Nonce && $pwNonce->purpose == 'password_reset2')
		{
			Log::trace('Processing final step of password reset');
			$preg_result = preg_match('/'.$config['passwordRegex'].'/',$post['reset_password']);
			if($preg_result != 1)
			{
				Authenticator::$request->systemMessages[] = "Password must match the pattern: " . $config['passwordRegexDescription'];
				$this->response = PasswordResetForm::resStage2Form;
				$this->user = $pwNonce->user;
			}
			elseif($post['reset_password'] != $post['reset_password_confirm'])
			{
				Authenticator::$request->systemMessages[] = 'Password and confirmation must match.';
				$this->response = PasswordResetForm::resStage2Form;
				$this->user = $pwNonce->user;
			}else{
				//turn the password into a hash before storing
				$hash = \password_hash($post['reset_password'], PASSWORD_BCRYPT);
				$db->query('UPDATE `ki_users` SET `password_hash`=? WHERE `id`=? LIMIT 1',
					array($hash, $pwNonce->user),
					'changing user password from reset form');
				$this->response = PasswordResetForm::resPasswordChanged;
			}
		}
	}
	
	/**
	* @return the HTML, depending on what state we're in as determined by the processing function.
	*/
	protected function getHTMLInternal()
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

	/**
	* This helper function encapsulates the "initial" form displayed before any input is recieved
	* @return initial form HTML string
	*/
	protected static function getInitialForm()
	{
		$formOpen = '<form method="post" action="' . $_SERVER['SCRIPT_NAME'] . '"><input type="email" name="email" placeholder="email" style="clear:both;" required/>';
		$formClose = '</form>';
		$tabs = array('Forgot Password' => $formOpen . '<input type="text" name="username" placeholder="username" required/><input type="submit" name="forgot" value="Reset"/>' . $formClose, 'Forgot Username' => $formOpen . '<input type="submit" name="forgot" value="Recover"/>' . $formClose);
		$forgotTabber = new RadTabber('reset', $tabs, false, array('height'=>'45px'));
		return $forgotTabber->getHTML();
	}

	/**
	* This helper function encapsulates the "stage 2" form displayed after the user clicks a
	* password reset nonce link in their email.
	* @return stage 2 form HTML string
	*/
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