<?php
namespace mls\ki;

class Util
{
	/**
	* Pagination function that determines the page numbers to link to
	* given the current page and highest page number
	*/
	public static function pagesToShow($current, $last)
	{
		$pagesOnEnds = 3;
		$pagesEachDirection = 2;
		$out = array();
		
		if($last == 0) return $out;
		
		$startOfMiddleSection = $current-$pagesEachDirection;
		$endOfMiddleSection = $current+$pagesEachDirection;
		
		for($i = 1; $i<=$pagesOnEnds && $i<=$last; ++$i)
		{
			$out[] = $i;
		}
		for($i = $startOfMiddleSection; $i<=$endOfMiddleSection && $i<=$last; ++$i)
		{
			if($i > $pagesOnEnds) $out[] = $i;
		}
		for($i = $last-$pagesOnEnds+1; $i<=$last; ++$i)
		{
			if($i > $endOfMiddleSection) $out[] = $i;
		}
		return $out;
	}

	/**
	* Convert any arbitrarily structured data to a string for debug purposes
	* The format is similar to "var_dump"
	* @param in The input data
	* @param html Whether to add the necessary bits for correct display when the output is interpreted as HTML. Doesn't add any fancy visual formatting.
	* @param indentLevel how deep to start indenting, 0 is usually where you want to start
	* @return the data in string form
	*/
	public static function toString($in, $html = false, $indentLevel = 0)
	{
		$out = '';
		$indent = str_pad('', $indentLevel);
		$indentNext = $indent . ' ';
		$nl = "\n";
		if($html)
		{
			$indent = str_replace(' ', '&nbsp;', $indent);
			$indentNext = $indent . '&nbsp;';
			$nl = '<br/>';
		}
		
		if(is_array($in) || is_object($in))
		{
			$open  = '[';
			$close = ']';
			$map   = ' => ';
			if(is_object($in))
			{
				$open  = '{';
				$close = '}';
				$map   = ' -> ';
			}
			if($html)
			{
				$open = htmlspecialchars($open);
				$close = htmlspecialchars($close);
				$map = htmlspecialchars($map);
			}
			$elements = array();
			foreach($in as $key => $value)
			{
				$elements[] = $indentNext . $key . $map . Util::toString($value, $html, $indentLevel+1);
			}
			$out = $open . $nl . implode(','.$nl, $elements) . $nl . $indent . $close;
		}else{
			if(is_bool($in))
			{
				$out = $in ? 'true' : 'false';
			}
			else if(is_numeric($in))
			{
				$out = $in;
			}else{
				$out = '"' . $in . '"';
				if($html) $out = htmlspecialchars($out);
			}
		}
		return $out;
	}

	public static function getUrl()
	{
		static $url = NULL;
		if($url === NULL)
		{
			$ssl = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] == 'on';	
			$scheme = $_SERVER['REQUEST_SCHEME'] . '://';
			$host   = $_SERVER['HTTP_HOST'];
			//$port   = (($_SERVER['SERVER_PORT'] == 80 && !$ssl) || ($_SERVER['SERVER_PORT'] == 443 && $ssl)) ? '' : $_SERVER['SERVER_PORT'];
			$req    = $_SERVER['SCRIPT_NAME'];
			$url = $scheme.$host.$req;
		}
		return $url;
	}

	public static function startsWith($haystack, $needle)
	{
		return $needle == '' || mb_strpos($haystack, $needle) === 0;
	}

	public static function endsWith($haystack, $needle)
	{
		return (string)$needle === mb_substr($haystack, -mb_strlen($needle));
	}
	
	public static function contains($haystack, $needle)
	{
		return strpos($haystack, $needle) !== false;
	}

	/*
	* Cryptographically-secure random string with a specified length in characters
	* If using a given keyspace with multi-byte characters, the length in bytes can vary
	*/
	public static function random_str($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
	{
		$str = '';
		$max = mb_strlen($keyspace) - 1;
		for($i = 0; $i < $length; ++$i)
			$str .= mb_substr($keyspace, random_int(0, $max), 1);
		return $str;
	}
	
	/**
	* Rightward bit-shift in an unsigned way, like the >>> operator in javascript
	* @param subject the number to shift
	* @param places the number of bits that the subject will be shifted
	* @return the result of shifting
	*/
	public static function unsignedRightShift($subject, $places)
	{
		$a = $subject;
		$b = $places;
		if($b >= 32 || $b < -32)
		{
			$m = (int)($b/32);
			$b = $b-($m*32);
		}

		if($b < 0) $b = 32 + $b;
		if($b == 0) return (($a>>1)&0x7fffffff)*2+(($a>>$b)&1);

		if($a < 0) 
		{ 
			$a = ($a >> 1); 
			$a &= 2147483647; 
			$a |= 0x40000000; 
			$a = ($a >> ($b - 1)); 
		}else{ 
			$a = ($a >> $b); 
		} 
		return $a; 
	}
	
	/**
	* Return a reference into a multidimensional array based on a dynamic list of indices.
	* If the referenced location doesn't exist,
	* it is recursively initialized with the end value being NULL.
	* @param a The root of the array
	* @param indexList A series of indicies leading into $a
	* @return reference to the indicated location
	*/
	public static function &arrayDynamicRef(array &$a, array $indexList)
	{
		$out =& $a;
		foreach($indexList as $i)
		{
			$out =& $out[$i];
		}
		return $out;
	}
	
	/**
	* Make a command-line call (like exec, proc_open, etc)
	* @param cmdLine the command to execute
	* @param stdin   any content to pipe into STDIN
	* @return an array with indices (stdout, stderr, exitcode)
	*         the values for which are the contents of those things returned by the called program
	*         OR string on failure
	*/
	public static function cmd(string $cmdLine, string $stdin = '')
	{
		$stdout = '';
		$ioDescriptors = array(
			0 => ["pipe", "r"], //for STDIN,  child reads from pipe
			1 => ["pipe", "w"], //for STDOUT, child writes to pipe
			2 => ["pipe", "w"]  //for STDERR, child writes to pipe
		);
		$pipes = []; //will contain file pointers to the i/o for the command
		$exitcode = NULL;
		$procResource = proc_open($cmdLine, $ioDescriptors, $pipes);
		if($procResource === false) return 'could not open process';
		$writeResult = fwrite($pipes[0], $stdin);
		while(true)
		{
			$status = proc_get_status($procResource);
			if(!$status['running'])
			{
				$exitcode = $status['exitcode'];
				break;
			}
			usleep(200000);
		}
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($procResource);
		
		if($writeResult === false) return 'could not send data to process';
		if($stdout === false || $stderr === false) return 'could not read output from process';
		return ['stdout' => $stdout, 'stderr' => $stderr, 'exitcode' => $exitcode];
	}
}
?>