<?php
namespace mls\ki\Security;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Mail;
use \mls\ki\Util;
use \mls\ki\Widgets\DataTable;
use \mls\ki\Widgets\DataTableField;
use \mls\ki\Widgets\DataTableEventCallbacks;
use \PHPMailer\PHPMailer\PHPMailer;

/**
* Data about a currently logged in user.
*/
class User
{
	//Database fields
	public $id;
	public $username;
	public $email;
	public $email_verified;
	public $password_hash;
	public $enabled;
	public $last_active;
	public $lockout_until;
	
	//Derived fields
	public $lockedOut;
	
	public $permissions = array();
	
	function __construct(int    $id,
	                     string $username,
						 string $email,
						 bool   $email_verified,
						 string $password_hash,
						 bool   $enabled,
						        $last_active,
						        $lockout_until)
	{
		$this->id             = $id;
		$this->username       = $username;
		$this->email          = $email;
		$this->email_verified = $email_verified;
		$this->password_hash  = $password_hash;
		$this->enabled        = $enabled;
		$this->last_active    = $last_active;
		$this->lockout_until  = $lockout_until;
		
		$this->lockedOut = $lockout_until >= time();
		
		$rConfig = Config::get()['root'];
		if($username == 'root' && !$rConfig['enable_root']) $this->enabled = false;

		$db = Database::db();
		$perms = false;
		if($username != 'root')
		{
			
			$perms = $db->query('SELECT `name` FROM `ki_permissions` WHERE `id` IN('
					. 'SELECT `permission` FROM `ki_permissionsOfGroup` WHERE `group` IN('
					. 'SELECT `group` FROM `ki_groupsOfUser` WHERE `user`=?))',
					array($id), 'getting permissions of user');
		}else{
			$perms = $db->query('SELECT `name` FROM `ki_permissions`',
					array(), 'getting all permissions');
		}
		if($perms !== false)
		{
			foreach($perms as $row)
			{
				$this->permissions[$row['name']] = true;
			}
		}
	}
	
	/**
	* Send the mail for confirming this user's email address
	*/
	public function sendEmailConfirmation()
	{
		$site = Config::get()['general']['sitename'];
		$nonce = Nonce::create('email_verify', $this->id, false, false);
		$mail = new PHPMailer();
		$mail->From = 'noreply@' . $_SERVER['SERVER_NAME'];
		$mail->FromName = $site . ' Account Management';
		$mail->addAddress($this->email);
		$mail->Subject = $site . ' Account Creation';
		$link = Util::getUrl() . '?ki_spn_ec=' . $nonce->nonceValue;
		$mail->Body = Authenticator::msg_AccountVerifyInstruction . "\n" . $link;
		Mail::send($mail);
	}
	
	/**
	* Load the user's data, given input credentials from a login attempt (if valid).
	* Take no other action either way.
	* @param username input username
	* @param password input password
	* @param requestContext a Request object representing the request within which the login attempt is being made
	* @return a User object on success; false on error; NULL on bad credentials
	*/
	public static function loadFromCreds(string $username, string $password, Request $requestContext)
	{
		$db = Database::db();
		$user = $db->query('SELECT `id`,`email`,`email_verified`,`password_hash`,`enabled`,UNIX_TIMESTAMP(`last_active`) AS last_active,UNIX_TIMESTAMP(`lockout_until`) AS lockout_until FROM `ki_users` WHERE `username`=? LIMIT 1',
			array($username), 'Checking for username');
		if($user === false) return false;

		if(empty($user) || $username == 'root')
		{
			//do a dummy password verify to prevent username discovery via timing
			password_verify($password, '$2y$10$0y05iFvBxofjmT553tTAeepX/1/tMilFfT/HrnlybDj5dedmq5izu');
		}
		if(empty($user)) return NULL;
		if($username == 'root')
		{
			$rConfig = Config::get()['root'];
			if($password != $rConfig['root_password']) return NULL;
			if($requestContext->ipAddress != $rConfig['root_ip']) return NULL;
		}else{
			if(!password_verify($password, $user[0]['password_hash'])) return NULL;
		}
		
		$user = $user[0];
		$ret =  new User($user['id'],
		                 $username,
		                 $user['email'],
		                 $user['email_verified'],
		                 $user['password_hash'],
		                 $user['enabled'],
		                 $user['last_active'],
		                 $user['lockout_until']);
		return $ret;
	}
	
	/**
	* Load the user's data given their ID.
	* Take no other action regardless of success.
	* @param id the user's ID a Request object representing the request within which the user is being loaded
	* @return a User object on success; false on error; NULL on bad input
	*/
	public static function loadFromId(int $id)
	{
		$db = Database::db();
		$user = $db->query('SELECT `username`,`email`,`email_verified`,`password_hash`,`enabled`,UNIX_TIMESTAMP(`last_active`) AS last_active,UNIX_TIMESTAMP(`lockout_until`) AS lockout_until FROM `ki_users` WHERE `id`=? LIMIT 1',
			array($id), 'Getting user by ID');
		if($user === false) return false;
		if(empty($user)) return NULL;
		$user = $user[0];
		$ret =  new User($id,
		                 $user['username'],
		                 $user['email'],
		                 $user['email_verified'],
		                 $user['password_hash'],
		                 $user['enabled'],
		                 $user['last_active'],
		                 $user['lockout_until']);
		return $ret;
	}
	
	/**
	* @return a DataTable that provides an admin interface for editing users.
	*/
	public static function getUserAdmin()
	{
		$addHashControls = function($cell, $type)
		{
			$out = '';
			
			if($type == 'edit')
			{
				$opts = '<select name="ki_hashAction">'
				. '<option value="hash" selected>Hash</option>'
				. '<option value="plain">Plaintext</option>'
				. '</select>';
				$out = $cell . ' ' . $opts;
			}
			elseif($type == 'add')
			{
				$opts = '<select name="ki_hashAction">'
				. '<option value="hash">Hash</option>'
				. '<option value="plain" selected>Plaintext</option>'
				. '</select>';
				$out = $cell . ' ' . $opts;
			}else{
				$out = '<span style="font-size:50%;">' . $cell . '</span>';
			}
			
			return $out;
		};
		
		$userFields = array();
		$userFields[] = new DataTableField('id',            'ki_users', 'ID',             true, false, false);
		$userFields[] = new DataTableField('username',      'ki_users', 'Username',       true, false, true );
		$userFields[] = new DataTableField('email',         'ki_users', 'Email address',  true, true,  true );
		$userFields[] = new DataTableField('email_verified','ki_users', 'Email verified?',true, true,  false);
		$userFields[] = new DataTableField('password_hash', 'ki_users', 'Password',       true, true,  true, [], $addHashControls);
		$userFields[] = new DataTableField('enabled',       'ki_users', 'Enabled?',       true, true,  true );
		$userFields[] = new DataTableField('last_active',   'ki_users', 'Last Active',    true, false, false);
		$userFields[] = new DataTableField('lockout_until', 'ki_users', 'Lockout Until:', true, true,  false);
		
		$beforeEdit = function(&$row)
		{
			$pol = Config::get()['policies'];
			$pw = $row['ki_users.password_hash'];
			switch($_POST['ki_hashAction'])
			{
				case 'plain':
				if(mb_strlen($pw) == 60 && mb_substr_count($pw, '$') == 3)
					return "Password was input as plaintext but looks like a hash. Not saved.";
				if(!preg_match('/'.$pol['passwordRegex'].'/', $pw))
					return 'Non-compliant password: ' . $pol['passwordRegexDescription'];
				$row['ki_users.password_hash'] = \password_hash($row['ki_users.password_hash'], PASSWORD_BCRYPT);
				break;
				
				case 'hash':
				default:
				if(mb_strlen($pw) != 60 || mb_substr_count($pw, '$') != 3)
					return "Password was input as hash but doesn't look like a hash. Not saved.";
			}
			return true;
		};
	
		$callbacks = new DataTableEventCallbacks(NULL, NULL, NULL, $beforeEdit, $beforeEdit, NULL);
		$filter = 'username != "root"';
		
		return new DataTable('userAdmin','ki_users', $userFields, true, true, $filter, 50, true, true, false, false, $callbacks);
	}
}
?>