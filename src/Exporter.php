<?php
namespace mls\ki;
use \PhpOffice\PhpSpreadsheet\Cell\Cell;
use \PhpOffice\PhpSpreadsheet\Cell\DataType;
use \PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use \PhpOffice\PhpSpreadsheet\Cell\IValueBinder;
use \PhpOffice\PhpSpreadsheet\Spreadsheet;
use \PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use \PhpOffice\PhpSpreadsheet\Writer\Ods;

class Exporter extends DefaultValueBinder implements IValueBinder
{
	/**
	* @param in 2 dimensional array with serial elements
	* @return XLSX file contents having the input data
	*/
	static function XLSX(array $in)
	{
		return Exporter::phpspreadsheet($in, 'Xlsx');
	}
	
	/**
	* @param in 2 dimensional array with serial elements
	* @return ODS (OpenOffice Calc) file contents having the input data
	*/
	static function ODS(array $in)
	{
		return Exporter::phpspreadsheet($in, 'Ods');
	}
	
	/**
	* This is only here as a helper to the XLSX function. phpspreadsheet requires
	* that this functionality be provided as a class having this as one of its methods
	* rather than being able to just take an anonymous callback function which would have
	* been cleaner. This is also the only reason Exporter has extend/implement clauses into
	* phpspreadsheet stuff.
	* The reason for having this in the first place is it's the most efficient way of making
	* the entire spreadsheet default to plain text rather than letting Excel auto-mangle the data.
	* @param cell the phpspreadsheet cell that this function must set the value in
	* @param value the value to be put into the cell
	* @return true
	*/
    public function bindValue(Cell $cell, $value = null)
    {
		if(is_bool($value))
		{
			$cell->setValueExplicit($value?1:0, DataType::TYPE_STRING);
		}else{
			$cell->setValueExplicit($value, DataType::TYPE_STRING);
		}
		return true;
    }
	
	/**
	* Handles the exports that we run through phpspreadsheet
	* Better for outside code to use the functions named after their formats for more clarity
	* and less error handling requirements
	* @param in 2 dimensional array with serial elements
	* @param format the name of a phpspreadsheet class extending Writer
	* @return the input data in the requested format
	*/
	static function phpSpreadsheet(array $in, string $format)
	{
		$format = '\\PhpOffice\\PhpSpreadsheet\\Writer\\' . $format;
		Cell::setValueBinder(new Exporter()); //Make every cell "text"; see Exporter::bindValue
		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->fromArray($in, null, 'A1', true);
		$writer = new $format($spreadsheet);
		
		/*
		 This amazingly stupid workaround is required because phpspreadsheet provides no way
		 to return its output; it can only write to a file. So we use the builtin PHP hack
		 that specifies a "filename" that when written to actually outputs to the browser,
		 then we use output buffering to capture it, preventing it from actually going to the
		 browser and saving it in a variable instead.
		*/
		\ob_start();
		$writer->save('php://output');
		$out = \ob_get_contents();
		\ob_end_clean();
		return $out;
	}
	
	/**
	* @param in 2 dimensional array with serial elements
	* @param quoteStrings Put " around strings. Useful if including a header row, to prevent Excel from autodetecting your CSV as something else (then giving an error) if the first column is something like "ID"
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
}
?>