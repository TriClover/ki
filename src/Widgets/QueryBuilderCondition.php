<?php
namespace mls\ki\Widgets;
use \mls\ki\Database;

/**
* Stores a single conditions from a QueryBuilder
*/
class QueryBuilderCondition
{
	public $field;
	public $operator;
	public $value;
	
	/**
	* @param field object for field that the condition is for.
	* @param operator the type of condition, as represented by an operator
	* @param value whatever value is required by the operator for evaluating what is in the field
	*/
	function __construct(\mls\ki\Widgets\DataTableField $field, string $operator, $value)
	{
		$this->field = $field;
		$this->operator = $operator;
		$this->value = $value;
	}
	
	/**
	* @return a SQL snippet expressing this condition
	*/
	function getSQL()
	{
		$db = Database::db();
		$out = '';
		switch($this->operator)
		{
			case '=':
			case '!=':
			case '<':
			case '<=':
			case '>':
			case '>=':
			$out = $this->field->fqName(true) . $this->operator . '"' . $db->esc($this->value) . '"';
			break;
			
			case 'contains':
			$out = $this->field->fqName(true) . ' LIKE "%' . $db->esc($this->value) . '%"';
			break;
			
			case 'does not contain':
			$out = $this->field->fqName(true) . ' NOT LIKE "%' . $db->esc($this->value) . '%"';
			break;
			
			case 'contained in':
			$out = '"' . $db->esc($this->value) . '" LIKE ("%" + ' . $this->field->fqName(true) . ' + "%")';
			break;
			
			case 'not contained in':
			$out = '"' . $db->esc($this->value) . '" NOT LIKE ("%" + ' . $this->field->fqName(true) . ' + "%")';
			break;
			
			case 'matches regex':
			$out = $this->field->fqName(true) . ' REGEXP "' . $db->esc($this->value) . '"';
			break;
			
			case "doesn't match regex":
			$out = $this->field->fqName(true) . ' NOT REGEXP "' . $db->esc($this->value) . '"';
			break;
			
			case 'is NULL':
			$out = $this->field->fqName(true) . ' IS NULL';
			break;
			
			case 'is NOT NULL':
			$out = $this->field->fqName(true) . ' IS NOT NULL';
		}
		
		if($this->field->manyToMany !== false)
		{
			$relation = $this->field->manyToMany;
			$relatedTable                = '`' . $relation->relatedTable  . '`';
			$relationTable               = '`' . $relation->relationTable . '`';
			$mainTablePKField            = $relation->mainTablePKFieldFQ();
			$relatedTablePKField         = $relation->relatedTablePKFieldFQ();
			$relatedTableDisplayField    = $relation->relatedTableDisplayFieldFQ();
			$relationTableRelatedFKField = $relation->relationTableRelatedFKFieldFQ();
			$relationTableMainFKField    = $relation->relationTableMainFKFieldFQ();

			//new
			if($this->value == '') $this->value = [];
			else                   $this->value = explode('', $this->value);
			$numTerms = count($this->value);
			
			$literalTable = [];
			foreach($this->value as $val)
			{
				$val = $db->esc($val);
				if(!is_numeric($val)) $val = '"'.$val.'"';
				$literalTable[] = $val;
			}
			$literalTable = implode(',', $literalTable);
			
			$subquery_howManyDesiredTermsArePresent = <<<QUERY_END
			SELECT COUNT(DISTINCT $relationTableRelatedFKField)
			FROM $relationTable
			WHERE $relationTableMainFKField = $mainTablePKField
				AND $relationTableRelatedFKField IN($literalTable)
QUERY_END;
			$subquery_findExtraTerms = <<<QUERY_END
			SELECT 1
			FROM $relationTable
			WHERE $relationTableMainFKField = $mainTablePKField
				AND $relationTableRelatedFKField NOT IN($literalTable)
QUERY_END;

			switch($this->operator)
			{
				case '=':
				if($numTerms > 0)
				{
					$out = "($subquery_howManyDesiredTermsArePresent) = $numTerms"
						. ' AND '
						. "NOT EXISTS($subquery_findExtraTerms)";
				}else{
					$out = "NOT EXISTS(SELECT 1 FROM $relationTable WHERE $relationTableMainFKField = $mainTablePKField)";
				}
				break;
				
				case '!=':
				if($numTerms > 0)
				{
					$out = "($subquery_howManyDesiredTermsArePresent) != $numTerms"
						. ' OR '
						. "EXISTS($subquery_findExtraTerms)";
				}else{
					$out = "EXISTS(SELECT 1 FROM $relationTable WHERE $relationTableMainFKField = $mainTablePKField)";
				}
				break;
				
				case 'contains':
				if($numTerms > 0)
					$out = "($subquery_howManyDesiredTermsArePresent) = $numTerms";
				else
					$out = '1';
				break;
				
				case 'contains any':
				if($numTerms > 0)
					$out = "($subquery_howManyDesiredTermsArePresent)>0";
				else
					$out = '1';
			}
		}
		
		return $out;
	}
}

?>