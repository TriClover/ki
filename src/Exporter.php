<?php
namespace mls\ki;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class Exporter
{
	/**
	* @param in 2 dimensional array with serial elements
	* @return XLSX file contents having the input data
	*/
	static function XLSX(array $in)
	{
		
	}
	
	/**
	* @param in 2 dimensional array with serial elements
	* @param delimiter The delimiter to use in the output
	* @param lineEnd The line ending to use in the output, default is unix style
	* @return CSV file contents having the input data
	*/
	static function CSV(array $in, bool $quoteStrings = false, string $delimiter = ',', string $lineEnd = "\n")
	{
		$out = [];
		foreach($in as $row)
		{
			$line = '';
			$between = false;
			foreach($row as $cell)
			{
				if($between) $line .= $delimiter;
				if(!is_numeric($cell) && $quoteStrings)
					$line .= '"' . $cell . '"';
				else
					$line .= $cell;
				$between = true;
			}
			$out[] = $line;
		}
		return implode($lineEnd, $out);
	}
	
	/**
	* @param in 2 dimensional array with serial elements
	* @return HTML file contents having the input data as a nice looking table that would also work well as the basis for a PDF
	*/
	static function HTML(array $in)
	{
	}
}
?>