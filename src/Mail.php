<?php
namespace mls\ki;
use \mls\ki\Database;
use \mls\ki\Log;

class Mail
{
	public $id;
	public $emailAddress;
	public $whenAdded;
	public $whenFirstVerifiedByAnyone;
	public $whenlastMailSent;
	
	public $associationId;
	public $whenAssociatedToCurrentUser;
	public $whenVerifiedForCurrentUser;
	
	function __construct(int    $id,
	                     string $emailAddress,
	                     string $whenAdded,
	                     string $whenFirstVerifiedByAnyone   = NULL,
	                     string $whenlastMailSent            = NULL,
	                     int    $associationId               = NULL,
	                     string $whenAssociatedToCurrentUser = NULL,
	                     string $whenVerifiedForCurrentUser  = NULL)
	 {
		$this->id = $id;
		$this->emailAddress = $emailAddress;
		$this->whenAdded = $whenAdded;
		$this->whenFirstVerifiedByAnyone = $whenFirstVerifiedByAnyone;
		$this->whenlastMailSent = $whenlastMailSent;
		$this->associationId = $associationId;
		if($associationId === NULL)
		{
			$whenAssociatedToCurrentUser = NULL; $whenVerifiedForCurrentUser = NULL;
		}
		$this->whenAssociatedToCurrentUser = $whenAssociatedToCurrentUser;
		$this->whenVerifiedForCurrentUser = $whenVerifiedForCurrentUser;
	 }
	 
	 
	
	/**
	 * Takes a phpmailer object and
	 * sends, logs, and/or adds environment indicator
	 * per the site config
	 *
	 * @param mail a PHPMailer object
	 * @param silent For use when logging via email - if true, don't log the email itself
	*/
	public static function send(\PHPMailer\PHPMailer\PHPMailer $mail, $silent = false)
	{
		Log::trace('Mailer: gathering info to perform checks', true);
		$toArray = array();
		foreach($mail->getToAddresses() as $add) $toArray[] = $add[0];
		if(empty($toArray))
		{
			Log::error('Tried to send mail with no recipients', true);
			return;
		}
		$to = \implode(',', $toArray);
		$regex = Config::get()['mail']['regexRecipientWhitelist'];
		$identify = ' to: ' . $to . ' subject: ' . $mail->Subject;
		$status = NULL;
		
		Log::trace('Mailer: checking mail against sending restrictions', true);
		if(!empty($regex))
		{
			$res = preg_match($regex, $to);
			if($res === false)
			{
				Log::error('An error occured checking mail against regexRecipientWhitelist. Not sending' . $identify, true);
				return;
			}
			else if($res == 0)
			{
				$status = 'regexFail';
			}
		}
		
		//enforce some properties on all mails
		$mail->CharSet = "UTF-8";

		//insert environment indicator
		$env = Config::get()['general']['environment'];
		if(!empty($env))
		{
			$notice = 'This mail is coming from the environment "' . $env . '" ' . "\n";
			if(!empty($mail->AltBody)) //then the mail is probably html
			{
				$mail->AltBody = $notice . $mail->AltBody;
				$notice = '<p style="color:#F00;background-color:#FFF;">'
					. $notice . '</p><br/>';
			}
			$mail->Body = $notice . $mail->Body;
		}
		
		//if sending is enabled and no checks failed
		if(Config::get()['mail']['send'] && $status === NULL)
		{
			if(!$mail->send())
			{
				$status = false;
				Log::error('Failed to send mail: ' . $mail->ErrorInfo, true);
				return;
			}else{
				$status = true;
			}	
		}
		Log::trace('Mailer: successful mail! Moving to logging step', true);
		
		//log if configured to do so, and not in silent mode
		if(!$silent)
		{
			Log::trace('Mailer: updating last-mail-sent date for addresses', true);
			//update Last Mail Sent
			$db = Database::db();
			$addressesPlaceholder = str_repeat('?,', count($toArray)-1) . '?';
			$mailCheckQuery = 'SELECT `id`,`emailAddress` FROM `ki_emailAddresses` WHERE `emailAddress` IN(' . $addressesPlaceholder . ')';
			$mailCheckRes = $db->query($mailCheckQuery, $toArray, 'Checking if email addresses are tracked in database');
			$emailsInDatabaseById = [];
			if($mailCheckRes !== false)
			{
				$foundAddresses = [];
				foreach($mailCheckRes as $row) $foundAddresses[$row['id']] = $row['emailAddress'];
				$foundPlaceholder = str_repeat('?,', count($foundAddresses)-1) . '?';
				$missingAddresses = array_diff($toArray, $foundAddresses);
				$addedAddresses = [];
				
				foreach($missingAddresses as $addr)
				{
					$mailAddQuery = 'INSERT INTO `ki_emailAddresses` SET `emailAddress`=?,`lastMailSent`=NOW()';
					$mailAddRes = $db->query($mailAddQuery, [$addr], 'adding email address to tracking in database');
					if($mailAddRes !== false)
						$addedAddresses[$db->connection->insert_id] = $addr;
				}
				$emailsInDatabaseById = $foundAddresses + $addedAddresses;
				if(!empty($foundAddresses))
				{
					$mailUpdateQuery = 'UPDATE `ki_emailAddresses` SET `lastMailSent`=NOW() WHERE `id` IN(' . $foundPlaceholder . ')';
					$db->query($mailUpdateQuery, array_keys($foundAddresses), 'updating last-mail-sent date for email address');
				}
			}

			Log::trace('Mailer: gathering info for logging', true);
			//build list of users associated with the addresses being sent to
			$emailsInDatabasePlaceholder = str_repeat('?,', count($emailsInDatabaseById)-1) . '?';
			$getUsersQuery = <<<END_SQL
			SELECT `ki_users`.`id` AS id, `ki_users`.`username` AS username
			FROM `ki_emailAddressesOfUser`
				LEFT JOIN `ki_users` ON `ki_emailAddressesOfUser`.`user`=`ki_users`.`id`
			WHERE `ki_emailAddressesOfUser`.`emailAddress` IN($emailsInDatabasePlaceholder);
END_SQL;
			$getUsersRes = $db->query($getUsersQuery, array_keys($emailsInDatabaseById), 'getting users associated with email addresses');
			$userList = [];
			if($getUsersRes !== false)
			{
				foreach($getUsersRes as $row)
				{
					$userList[] = $row['id'] . ':' . $row['username'];
				}
			}
			$userList = 'associated users: ' . implode(',', $userList);

			//build log line
			$event = 'Mail was sent';
			if($status === NULL)  $event = 'Mail is disabled, but would have been sent';
			if($status === 'regexFail')  $event = 'Mail address blocked by whitelist, but would have been sent';
			$line = $event . $identify . ' ' . $userList;
			
			//Log that a mail was sent, if configured
			$level = Config::get()['mail']['loglevel'];
			if(!empty($level))
			{
				if(!is_numeric($level))
				{
					$convert = constant('\mls\ki\Log::' . $level);
					if($convert !== NULL) $level = $convert;
				}
				Log::log($level, $line, true);
			}

			//Archive contents of sent mail, if configured
			$file = Config::get()['mail']['archivefile'];
			if(!empty($file))
			{
				Log::trace('Mailer: saving mail to file', true);
				$logdata = $line . "\n" . $mail->getMailMIME() . "\n" . $mail->Body . "\n\n";
				if(!empty($mail->AltBody)) $logdata .= "---Alt content:\n\n" . $mail->AltBody . "\n\n";
				$logdata .= "\n";
				$res = file_put_contents($file, $logdata, FILE_APPEND | LOCK_EX);
				if($res === false) Log::error('Archiving failed for mail ' . $identify);
			}
		}
	}
}
?>