<?php
namespace ki;

/**
 * Takes a phpmailer object and
 * sends, logs, and/or adds environment indicator
 * per the site config
 *
 * @param mail a PHPMailer object
 * @param silent For use when logging via email - if true, don't log the email itself
*/
function mail($mail, $silent = false)
{
	if(!($mail instanceof \PHPMailer\PHPMailer\PHPMailer))
	{
		Log::error('Non-mailer object sent to \ki\mail', true);
		return;
	}
	
	Log::trace('Mailer: gathering info to perform checks', true);
	$to = array();
	foreach($mail->getToAddresses() as $add) $to[] = $add[0];
	$to = \implode(',', $to);
	$regex = config()['mail']['regexRecipientWhitelist'];
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
	$env = config()['general']['environment'];
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
	if(config()['mail']['send'] && $status === NULL)
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
		Log::trace('Mailer: gathering info for logging', true);
		$event = 'Mail was sent';
		if($status === NULL)  $event = 'Mail is disabled, but would have been sent';
		if($status === 'regexFail')  $event = 'Mail address blocked by whitelist, but would have been sent';
		$line = $event . $identify;
			
		$level = config()['mail']['loglevel'];
		if(!empty($level))
		{
			if(!is_numeric($level))
			{
				$convert = constant('\ki\Log::' . $level);
				if($convert !== NULL) $level = $convert;
			}
			Log::log($level, $line, true);
		}

		$file = config()['mail']['archivefile'];
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

?>