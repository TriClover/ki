<?php
namespace mls\ki\Security;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Mail;
use \mls\ki\Util;
use \mls\ki\Widgets\DataTable;
use \mls\ki\Widgets\DataTableField;
use \mls\ki\Widgets\DataTableEventCallbacks;
use \mls\ki\Widgets\LoginForm;
use \PHPMailer\PHPMailer\PHPMailer;

/**
* Data about a currently logged in user.
*/
class User
{
	//Database fields
	public $id;
	public $username;
	public $emails;
	public $defaultEmail;
	public $password_hash;
	public $enabled;
	public $last_active;
	public $lockout_until;
	
	//Derived fields
	public $lockedOut;
	
	public $permissionsById = array();
	public $permissionsByName = array();
	
	function __construct(int          $id,
	                     string       $username,
						 array        $emails,
						 \mls\ki\Mail $defaultEmail=NULL,
						 string       $password_hash,
						 bool         $enabled,
						              $last_active,
						              $lockout_until)
	{
		$this->id             = $id;
		$this->username       = $username;
		$this->emails         = $emails;
		$this->defaultEmail   = $defaultEmail;
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
			
			$perms = $db->query('SELECT `id`,`name` FROM `ki_permissions` WHERE `id` IN('
					. 'SELECT `permission` FROM `ki_permissionsOfGroup` WHERE `group` IN('
					. 'SELECT `group` FROM `ki_groupsOfUser` WHERE `user`=?))',
					array($id), 'getting permissions of user');
		}else{
			$perms = $db->query('SELECT `id`,`name` FROM `ki_permissions`',
					array(), 'getting all permissions');
		}
		if($perms !== false)
		{
			foreach($perms as $row)
			{
				$this->permissionsById[$row['id']] = $row['name'];
				$this->permissionsByName[$row['name']] = $row['id'];
			}
		}
	}
	
	/**
	* Send the mail for confirming this user's email address
	* @param addrObj Which of the addresses to confirm for this user. If NULL, send to all unverified addresses.
	*/
	public function sendEmailConfirmation(\mls\ki\Mail $addrObj = NULL)
	{
		$mails = [];
		if($addrObj === NULL)
		{
			$mails = $this->getUnverifiedEmails();
		}else{
			$mails = [$addrObj];
		}
		
		foreach($mails as $m)
		{
			$site = Config::get()['general']['sitename'];
			$nonce = Nonce::create('email_verify', $this->id, false, false, $m->id);
			$mail = new PHPMailer();
			$mail->From = 'noreply@' . $_SERVER['SERVER_NAME'];
			$mail->FromName = $site . ' Account Management';
			$mail->addAddress($m->emailAddress);
			$mail->Subject = $site . ' Email Verification';
			$link = Util::getUrl() . '?ki_spn_ec=' . $nonce->nonceValue;
			$mail->Body = Authenticator::msg_AccountVerifyInstruction . "\n" . $link;
			Mail::send($mail);
		}
	}
	
	/**
	* Send the mail for confirming this user's email address for logging in from a new location.
	* @param requestContext a Request object representing the request within which the login attempt is being made
	* @param address Which of the addresses to use. If NULL, use the default.
	*/
	public function sendEmailNonceForNewLocation(Request $requestContext, \mls\ki\Mail $address = NULL)
	{
		if($address === NULL) $address = $this->defaultEmail;
		
		$site = Config::get()['general']['sitename'];
		$nonce = Nonce::create('email_verify', $this->id, false, false, $address->id);
		$mail = new PHPMailer();
		$mail->From = 'noreply@' . $_SERVER['SERVER_NAME'];
		$mail->FromName = $site . ' Account Management';
		$mail->addAddress($address->emailAddress);
		$mail->Subject = $site . ' Login From New Location';
		$link = Util::getUrl() . '?ki_spn_ec=' . $nonce->nonceValue;
		$mail->Body    = 'We detected that you attempted to log in from a new location/device ' . $requestContext->ipAddress . '. Click the following link to allow logging in from your current location/device.<br/><b>This only enables login for your CURRENT location/device where you are clicking the link, not anywhere else!</b><br/>If the login attempt was not made by you, you should change your password.' . "\n" . $link;
		$mail->AltBody = 'We detected that you attempted to log in from a new location/device ' . $requestContext->ipAddress . '. Click the following link to allow logging in from your current location/device.        This only enables login for your CURRENT location/device where you are clicking the link, not anywhere else!         If the login attempt was not made by you, you should change your password.' . "\n" . $link;
		Mail::send($mail);
	}

	/**
	* @param ipId the database ID of an IP address
	* @return whether the user has logged in from the IP before, or NULL on error
	*/
	public function isTrustedIp(int $ipId)
	{
		$db = Database::db();
		$query = 'SELECT ? IN(SELECT ip FROM ki_sessions WHERE user=? UNION SELECT ip FROM ki_sessionsArchive WHERE user=?) AS trusted';
		$res = $db->query($query, [$ipId, $this->id, $this->id], 'checking session history for ip');
		if($res === false || empty($res)) return NULL;
		$row = $res[0];
		return $row['trusted'] == 1;
	}
	
	public function getVerifiedEmails()
	{
		$verifiedEmails = [];
		foreach($this->emails as $mail)
		{
			if($mail->whenVerifiedForCurrentUser !== NULL) $verifiedEmails[] = $mail;
		}
		return $verifiedEmails;
	}
	
	public function getUnverifiedEmails()
	{
		$unverifiedEmails = [];
		foreach($this->emails as $mail)
		{
			if($mail->whenVerifiedForCurrentUser === NULL) $unverifiedEmails[] = $mail;
		}
		return $unverifiedEmails;
	}
	
	public function hasVerifiedEmail()
	{
		foreach($this->emails as $mail)
		{
			if($mail->whenVerifiedForCurrentUser !== NULL) return true;
		}
		return false;
	}
	
	public static function getEmailsOfUser(int $userId)
	{
		$db = Database::db();
		$emailQuery = <<<END_SQL
		SELECT
			`ki_emailAddresses`.`id`               AS emailAddressId,
			`ki_emailAddresses`.`emailAddress`     AS address,
			`ki_emailAddresses`.`added`            AS whenAdded,
			`ki_emailAddresses`.`firstVerified`    AS firstVerified,
			`ki_emailAddresses`.`lastMailSent`     AS lastMailSent,
			`ki_emailAddressesOfUser`.`id`         AS associationId,
			`ki_emailAddressesOfUser`.`associated` AS whenAssociatedToCurrentUser,
			`ki_emailAddressesOfUser`.`verified`   AS whenVerifiedForCurrentUser,
			(SELECT `defaultEmailAddress` FROM `ki_users` WHERE `ki_users`.`id`=?)
				= `ki_emailAddressesOfUser`.`id`   AS isDefault
		FROM `ki_emailAddressesOfUser`
			LEFT JOIN `ki_emailAddresses` ON `ki_emailAddressesOfUser`.`emailAddress`=`ki_emailAddresses`.`id`
		WHERE `ki_emailAddressesOfUser`.`user`=?
END_SQL;
		$emailRes = $db->query($emailQuery, [$userId,$userId], 'getting email addresses of user');
		if($emailRes === false) return false;
		$emails = [];
		$defaultEmail = NULL;
		foreach($emailRes as $row)
		{
			$mail = new Mail($row['emailAddressId'],
			                 $row['address'],
			                 $row['whenAdded'],
			                 $row['firstVerified'],
			                 $row['lastMailSent'],
			                 $row['associationId'],
			                 $row['whenAssociatedToCurrentUser'],
			                 $row['whenVerifiedForCurrentUser']);
			$emails[] = $mail;
			if($row['isDefault']) $defaultEmail = $mail;
		}
		return ['emails' => $emails, 'defaultEmail' => $defaultEmail];
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
		$user = $db->query('SELECT `id`,`password_hash`,`enabled`,UNIX_TIMESTAMP(`last_active`) AS last_active,UNIX_TIMESTAMP(`lockout_until`) AS lockout_until FROM `ki_users` WHERE `username`=? LIMIT 1',
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
		
		//fill email address array
		$emailsMap = User::getEmailsOfUser($user['id']);
		if($emailsMap === false) return false;
		$emails = $emailsMap['emails'];
		$defaultEmail = $emailsMap['defaultEmail'];
		
		$ret = new User($user['id'],
		                $username,
		                $emails,
		                $defaultEmail,
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
		$user = $db->query('SELECT `username`,`password_hash`,`enabled`,UNIX_TIMESTAMP(`last_active`) AS last_active,UNIX_TIMESTAMP(`lockout_until`) AS lockout_until FROM `ki_users` WHERE `id`=? LIMIT 1',
			array($id), 'Getting user by ID');
		if($user === false) return false;
		if(empty($user)) return NULL;
		$user = $user[0];
		
		//fill email address array
		$emailsMap = User::getEmailsOfUser($id);
		if($emailsMap === false) return false;
		$emails = $emailsMap['emails'];
		$defaultEmail = $emailsMap['defaultEmail'];
		
		$ret =  new User($id,
		                 $user['username'],
		                 $emails,
		                 $defaultEmail,
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
		if(isset($_POST['editEmail'])) return LoginForm::getEmailEditor($_POST['editEmail']);
		if(isset($_GET[ 'editEmail'])) return LoginForm::getEmailEditor($_GET[ 'editEmail']);
		
		$addHashControls = function($cell, $type, &$row)
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
		
		$formatEmailCell = function($cell, $type, &$row)
		{
			$out = $cell;
			if($type == 'show')
			{
				$styles = 'white-space:nowrap;font-size:80%;';
				
				$out = str_replace(' ','&nbsp;',$out);
				$out = str_replace(',','<br/>',$out);
				$out .= '<br/><a href="?editEmail=' . $row['ki_users.id'] . '">Edit</a>';
				$out = '<span style="' . $styles . '">' . $out . '</span>';
			}
			return $out;
		};

		$userFields = array();
		$emailsQuery = <<<END_SQL
		(
			SELECT
				GROUP_CONCAT(CONCAT(
					`ki_emailAddresses`.`emailAddress`,
					IF(`ki_users`.`defaultEmailAddress` = `ki_emailAddressesOfUser`.`id`," (Def.)",""),
					IF(`ki_emailAddressesOfUser`.`verified` IS NULL," (unverified)","")
				))
			FROM `ki_emailAddressesOfUser`
				LEFT JOIN `ki_emailAddresses` ON `ki_emailAddressesOfUser`.`emailAddress`=`ki_emailAddresses`.`id`
			WHERE `ki_emailAddressesOfUser`.`user`=`ki_users`.`id`
		)
END_SQL;
		$userFields[] = new DataTableField('id',            'ki_users', 'ID',             true, false, false);
		$userFields[] = new DataTableField('username',      'ki_users', 'Username',       true, false, true );
		$userFields[] = new DataTableField($emailsQuery,    '',         'Email addresses',true, false, false, [], $formatEmailCell);
		$userFields[] = new DataTableField('password_hash', 'ki_users', 'Password',       true, true,  true,  [], $addHashControls);
		$userFields[] = new DataTableField('enabled',       'ki_users', 'Enabled?',       true, true,  true );
		$userFields[] = new DataTableField('last_active',   'ki_users', 'Last Active',    true, false, false);
		$userFields[] = new DataTableField('lockout_until', 'ki_users', 'Lockout Until:', true, true,  false);
		$userFields[] = new DataTableField('name',          'ki_groups','Groups:',        true, true,  true, [], NULL, 200, true);
		$userFields[] = new DataTableField(NULL, '', '', false, false, false, [], NULL);
		
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
		$filter = '`username` != "root"';
		
		return new DataTable('userAdmin','ki_users', $userFields, true, true, $filter, 50, true, true, false, false, $callbacks);
	}
	
	/**
	* @return a DataTable that provides an admin interface for editing groups.
	*/
	public static function getGroupAdmin()
	{
		$groupFields = [];
		$groupFields[] = new DataTableField('id',          'ki_groups',      'ID',           true, false, false);
		$groupFields[] = new DataTableField('name',        'ki_groups',      'Name',         true, true,  true);
		$groupFields[] = new DataTableField('description', 'ki_groups',      'Description',  true, true,  true);
		$groupFields[] = new DataTableField('name',        'ki_permissions', 'Permissions:', true, true,  true, [], NULL, 200, true);
		return new DataTable('groupAdmin', 'ki_groups', $groupFields, true, true, '', 100);
	}
}
?>