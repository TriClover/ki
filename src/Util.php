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
	* Convert any arbitrarily structured data to a string
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

	function getUrl()
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

	function startsWith($haystack, $needle)
	{
		return $needle == '' || mb_strpos($haystack, $needle) === 0;
	}

	function endsWith($haystack, $needle)
	{
		return (string)$needle === mb_substr($haystack, -mb_strlen($needle));
	}

	/*
	* Cryptographically-secure random string with a specified length in characters
	* If using a given keyspace with multi-byte characters, the length in bytes can vary
	*/
	function random_str($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
	{
		$str = '';
		$max = mb_strlen($keyspace) - 1;
		for($i = 0; $i < $length; ++$i)
			$str .= mb_substr($keyspace, random_int(0, $max), 1);
		return $str;
	}
}
?>