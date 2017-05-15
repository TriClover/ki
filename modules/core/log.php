<?php
namespace ki;

class Log
{
	//define log levels
	const SYSTEMIC = 9; //something is really broken and it affects overall site integrity
	const FATAL    = 6; //something is really broken
	const ERROR    = 5; //major problems
	const WARN     = 4; //minor problems
	const INFO     = 3; //high level info on what is happening; processing checkpoints
	const DEBUG    = 2; //state info useful for debugging of certain features
	const TRACE    = 1; //maximum verbosity detailing every action
	
	const NONE     = 10;
	const ALL      = -1;
	
	static function log($level, $out, $forMail = false)
	{
		if(!Log::$isSetup) Log::setup();
		static $level2priority = array(
		'TRACE'   => LOG_DEBUG,
		'DEBUG'   => LOG_DEBUG,
		'INFO'    => LOG_INFO,
		'WARN'    => LOG_WARNING,
		'ERROR'   => LOG_ERR,
		'FATAL'   => LOG_CRIT,
		'SYSTEMIC'=> LOG_ALERT);
		$dt = date('Y-m-d H:i:s');
		$levelName = $level;
		$url = \ki\util\getUrl();
		if(is_numeric($level)) $levelName = Log::levelName($level);
		foreach(Log::$destinations as $dest)
		{
			if((is_numeric($level) && ($level >= $dest->threshold))
				|| in_array($level, $dest->namedLevels))
			{
				//file:filename, user (browser), mail:address, table:dbtitle.tablename, syslog, php (follows php.ini), sapi (apache)
				switch($dest->type)
				{
					case 'file':
					$map = array('when'=>$dt, 'level'=>$levelName, 'url'=>$url, 'user'=>'', 'message'=>$out);
					$line = '';
					switch($dest->format)
					{
						case 'json':
						$line = json_encode($map) . "\n";
						break;
						
						case 'html':
						$line = htmlspecialchars($dt . ' [' . $levelName . '] ' . $out . ' URL: ' . $url . ' User: ') . '<br/>' . "\n";
						break;
						
						case 'plain':
						default:
						$line = $dt . ' [' . $levelName . '] ' . $out . ' URL: ' . $url . ' User: ' . "\n";
					}
					file_put_contents($dest->address, $line, FILE_APPEND | LOCK_EX);
					break;
					
					case 'mail':
					//don't mail logs coming from the mail function
					$site = config()['general']['sitename'];
					$from = 'noreply@' . $_SERVER['SERVER_NAME'];

					if($forMail)
					{
						continue;
					}
					
					//put mail together
					$mail = new \PHPMailer\PHPMailer\PHPMailer;
					$map = array('when'=>$dt, 'level'=>$levelName, 'url'=>$url, 'user'=>'', 'message'=>$out);
					switch($dest->format)
					{
						case 'json':
						$mail->Body = json_encode($map) . "\n";
						break;
						
						case 'html':
						$mail->isHTML(true);
						$mail->Body = $dt . ' [' . $levelName . ']<br/>'
							. htmlspecialchars($out) . '<br/>URL: ' . $url . '<br/>User: ' . '<br/>';
						$mail->AltBody = $dt . ' [' . $levelName . "]\n" . $out . "\nURL: " . $url . "\nUser: \n";
						break;
						
						case 'plain':
						default:
						$mail->Body = $dt . ' [' . $levelName . "]\n" . $out . "\nURL: " . $url . "\nUser: \n";
					}
					
					$mail->From = $from;
					$mail->FromName = $site . ' Log Mailer';
					$mail->addAddress($dest->address);
					$mail->Subject = $site . ' Log [' . $levelName . ']';
					mail($mail);
					break;
					
					case 'table':
					$db = $dest->handle;
					$table = $dest->address;
					$query = 'INSERT INTO `' . $db->real_escape_string($table) . '` SET '
						. '`when`="' . $dt . '",'
						. '`level`="' . $db->real_escape_string($levelName) . '",'
						. '`url`="' . $db->real_escape_string($url) . '",'
						. '`user`="",'
						. '`message`="' . $db->real_escape_string($out) . '"';
					$db->query($query);
					break;
					
					case 'syslog':
					$pri = array_key_exists($levelName, $level2priority) ? $level2priority[$levelName] : $levelName;
					$map = array('level'=>$levelName, 'url'=>$url, 'user'=>'', 'message'=>$out);
					$line = '';
					switch($dest->format)
					{
						case 'json':
						$line = json_encode($map);
						break;

						case 'html':
						case 'plain':
						default:
						$line = '[' . $levelName . '] ' . $out . ' URL: ' . $url . ' USER: ';
					}
					openlog(config()['general']['sitename'], LOG_NDELAY | LOG_PID, LOG_USER);
					syslog($pri, $line);
					closelog();
					break;
					
					case 'php':
					$map = array('level'=>$levelName, 'url'=>$url, 'user'=>'', 'message'=>$out);
					$line = '';
					switch($dest->format)
					{
						case 'json':
						$line = json_encode($map);
						break;

						case 'html':
						case 'plain':
						default:
						$line = config()['general']['sitename'] . ' [' . $levelName . '] ' . $out . ' URL: ' . $url . ' USER: ';
					}
					error_log($line, 0);
					break;
					
					case 'sapi':
					$map = array('level'=>$levelName, 'url'=>$url, 'user'=>'', 'message'=>$out);
					$line = '';
					switch($dest->format)
					{
						case 'json':
						$line = json_encode($map);
						break;

						case 'html':
						case 'plain':
						default:
						$line = config()['general']['sitename'] . ' [' . $levelName . '] ' . $out . ' URL: ' . $url . ' USER: ';
					}
					error_log($line, 4);
					break;
					
					case 'user':
					default:
					$map = array('level'=>$levelName, 'message'=>$out);
					switch($dest->format)
					{
						case 'json':
						echo json_encode($map);
						break;
						
						case 'plain':
						echo '[' . $levelName . '] ' . $out . "\n";
						break;
						
						case 'html':
						default:
						echo '[' . $levelName . '] ' . $out . '<br/>';
					}
				}
			}
		}
	}
	
	static function systemic($out, $forMail = false) { Log::log(Log::SYSTEMIC, $out, $forMail); }
	static function fatal(   $out, $forMail = false) { Log::log(Log::FATAL,    $out, $forMail); }
	static function error(   $out, $forMail = false) { Log::log(Log::ERROR,    $out, $forMail); }
	static function warn (   $out, $forMail = false) { Log::log(Log::WARN,     $out, $forMail); }
	static function info (   $out, $forMail = false) { Log::log(Log::INFO,     $out, $forMail); }
	static function debug(   $out, $forMail = false) { Log::log(Log::DEBUG,    $out, $forMail); }
	static function trace(   $out, $forMail = false) { Log::log(Log::TRACE,    $out, $forMail); }
	
	static $destinations = array();
	static $isSetup = false;
	
	static function setup()
	{
		Log::$isSetup = true;
		$setupErrors = array();
		$setupWarns = array();
		
		foreach(config()['log']['destination'] as $index => $val)
		{
			//load config into destination objects
			$dest = new LogDestination();
			$colon = strpos($val, ':');
			if($colon === false)
			{
				$dest->type = $val;
			}else{
				$dest->type = substr($val,0,$colon);
				$dest->address = substr($val,$colon+1);
			}
			if(!in_array($dest->type, array('file','user','mail','table','syslog','php','sapi')))
			{
				$setupErrors[] = 'Unrecognized log destination: ' . $val;
				continue;
			}
			$dest->format = config()['log']['format'][$index];
			if(!in_array($dest->format, array('plain','json','html')))
			{
				$dest->format = 'plain';
				$setupWarns[] = 'Unrecognized log format: ' . $dest->format . ' for destination: ' . $val . ' (defaulting to plain)';
			}

			$inThreshold = config()['log']['threshold'][$index];
			if(!is_numeric($inThreshold))
			{
				$convert = constant('\ki\Log::' . $inThreshold);
				if($convert === NULL)
				{
					$setupWarns[] = 'Unrecognized log threshold: ' . $inThreshold . ' for destination: ' . $val . ' (defaulting to ERROR)';
					$inThreshold = Log::ERROR;
				}else{
					$inThreshold = $convert;
				}
			}
			$dest->threshold = $inThreshold;
			$dest->namedLevels = explode(',', config()['log']['namedlevels'][$index]);
			
			//check that the specified resources, if any, are valid
			if($dest->type == 'mail')
			{
				if(filter_var($dest->address, FILTER_VALIDATE_EMAIL) === false)
				{
					$setupErrors[] = 'Bad email address: ' . $dest->address . ' for logger';
					continue;
				}
			}
			if($dest->type == 'file')
			{
				if(file_exists($dest->address))
				{
					if(is_dir($dest->address))
					{
						$setupErrors[] = 'File name given for logger is actually directory: ' . $dest->address;
						continue;
					}
					if(!is_writable($dest->address))
					{
						$setupErrors[] = 'File name given for logger is not writable: ' . $dest->address;
						continue;
					}
				}else{
					$res = fopen($dest->address, 'a');
					if($res === false)
					{
						$setupErrors[] = "Couldn't create file for logger: " . $dest->address;
						continue;
					}
					if(fclose($res) === false)
					{
						$setupWarns[] = 'Failed to close file after creating it for logger: ' . $dest->address;
					}
				}
			}
			if($dest->type == 'table')
			{
				$dest->address = str_replace('`', '', $dest->address);
				$dot = strpos($dest->address, '.');
				if($dot === false)
				{
					$setupErrors[] = "No DB specified for log table: " . $dest->address;
					continue;
				}
				$dbtitle = substr($dest->address,0,$dot);
				$table = substr($dest->address,$dot+1);
				$db = \ki\db($dbtitle);
				if($db === NULL)
				{
					$setupErrors[] = "Invalid DB specified for log: " . $dbtitle;
					continue;
				}
				$dest->handle = $db;
				$dest->address = $table;
				$query = 'SHOW COLUMNS FROM ' . $db->real_escape_string($table);
				$res = $db->query($query);
				if($res === false)
				{
					$setupErrors[] = "Invalid table specified for logger: " . $dest->address;
					continue;
				}
				$rows = array('id'=>'int',
				              'when'=>'datetime',
							  'level'=>'varchar',
							  'url'=>'text',
							  'user'=>'varchar',
							  'message'=>'text');
				$schemaGood = true;
				while($row = $res->fetch_assoc())
				{
					if(!array_key_exists($row['Field'], $rows)) continue; //tolerate extra cols
					if(mb_strpos($row['Type'],$rows[$row['Field']]) === false)
					{
						$setupErrors[] = "Table specified for logger has field with wrong type: "
							. $row['Field'];
						$schemaGood = false;
						break;
					}
					if($row['Field'] == 'id' && mb_strpos($row['Extra'],'auto_increment') === false)
					{
						$setupErrors[] = "Table specified for logger has id without auto_increment: "
							. $dest->address;
						$schemaGood = false;
						break;
					}
					if($row['Field'] == 'user' && $row['Null'] != 'YES')
					{
						$setupErrors[] = "Table specified for logger has non-nullable user field: "
							. $dest->address;
						$schemaGood = false;
						break;
					}
					
					unset($rows[$row['Field']]);
				}
				if(!$schemaGood) continue;
				if(!empty($rows))
				{
					$setupErrors[] = "Missing fields in table specified for logger: "
						. $dest->address . ' -- ' . implode(',', array_keys($rows));
					continue;
				}
			}
			Log::$destinations[] = $dest;
		}
		//if some loggers failed to setup and none are left to log it
		if(!empty($setupErrors) && empty(Log::$destinations))
		{
			//panic
			$msg = '[SYSTEMIC] ' . config()['general']['sitename']
				. ' - All configured loggers failed setup! ' . implode(';', $setupErrors);
			error_log($msg, 0);
			error_log($msg, 4);
			return;
		}
		
		foreach($setupWarns as $msg)  Log::warn($msg);
		foreach($setupErrors as $msg) Log::error($msg);
	}
	
	static function levelName($level)
	{
		 $names = array_flip((new \ReflectionClass(__CLASS__))->getConstants());
		 return $names[$level];
	}
}

class LogDestination
{
	public $type;       //file:filename, user (browser), mail:address, table:dbtitle.tablename, syslog, php (follows php.ini), sapi (apache)
	public $address;    //filename, tablename, email address, etc
	public $handle;     //db handle, etc. (don't keep files open over the whole request though)
	public $format;     //plain, json, html
	public $threshold;  //minimum ordered level that will be logged as number
	public $namedLevels;//array of custom named log levels captured by this logger
}

?>