//General Util
function objToString (obj,iter)
{
	if(iter === undefined) iter = 0;
	if((typeof obj) != "object") return obj;
	if(iter > 3) return 'Object';
	
	var spacer = '             '.substring(0,iter);
    var str = '';
    for(var p in obj) {
        if(obj.hasOwnProperty(p))
		{
			if((typeof obj[p]) == "object")
			{
				str += spacer + p + '::' + objToString(obj[p], iter+1) + '\n';
			}else{
				str += spacer + p + '::' + obj[p] + '\n';
			}
        }
    }
    return str;
}

//Tabber (hacks for missing CSS4 features)
$(document).ready(function()
{
	//set default tab if active tab was not specified
	if(window.location.hash.substr(1).length < 1)
	{
		$(".ki_tabber>div:first-child:not(:has(~div:target))>a").css("background-color","#FFFFFF");
		$(".ki_tabber>div:first-child:not(:has(~div:target))>a").css("z-index","5");
		$(".ki_tabber>div:first-child:not(:has(~div:target))>div").css("visibility","visible");
	}
	//remove indication of default tab once any tab is clicked
	$(".ki_tabber>div>a").click(function()
	{
		$(".ki_tabber>div:first-child:not(:has(~div:target))>a").css("background-color","");
		$(".ki_tabber>div:first-child:not(:has(~div:target))>a").css("z-index","");
		$(".ki_tabber>div:first-child:not(:has(~div:target))>div").css("visibility","");
	});
});

//Environment Indicator
function kiEnvIndClose()
{
	$('#ki_environmentIndicator').css('display','none');
}

//DataTable
function ki_setEditVisibility(btn, inputValues)
{
	var buttonFields = btn.parent().parent().find('.ki_table_input').not('.ws-inputreplace');
	var delBtn = btn.parent().parent().find('.ki_button_confirm_container');
	for(var i = 0; i < buttonFields.length; i++)
	{
		var control = $(buttonFields[i]);
		var changed = false;
		if(control.attr('type') == "checkbox")
		{
			changed = (control.prop('checked') == true) != (inputValues[control.attr('id')] == true);
		}else{
			changed = control.prop('value') != inputValues[control.attr('id')];
		}
		if(changed)
		{
			btn.css("z-index","30");
			return;
		}
	}
	btn.css("z-index","5");
}

//QueryBuilder
var ki_querybuilder_fields = ki_querybuilder_fields || new Object();

function ki_setupQueryBuilder(prefix)
{
	$('#' + prefix + ' ol').sortable();
	var previousResult = $('#' + prefix + '_filterResult').val();
	if(previousResult)
	{
		previousResult = JSON.parse(previousResult);
	}
	
	if(previousResult && previousResult['rootConditionGroup'])
	{
		$('#' + prefix + '_filter').html(ki_queryBuilderGroup(prefix, previousResult['rootConditionGroup']['boolOp'], previousResult['rootConditionGroup']['conditions'], true));
	}else{
		$('#' + prefix + '_filter').html(ki_queryBuilderGroup(prefix, 'AND', [], true));
	}
}

function ki_queryBuilderGroup(prefix, boolOp, conditions, root)
{
	if(typeof(boolOp)     === 'undefined') boolOp = 'AND';
	if(typeof(conditions) === 'undefined') conditions = [];
	if(typeof(root)       === 'undefined') root = false;

	var content = '';
	for(var index in conditions)
	{
		var condition = conditions[index];
		var contentLine = '';
		if('boolOp' in condition)
		{
			contentLine += ki_queryBuilderGroup(prefix, condition['boolOp'], condition['conditions'], false);
		}else{
			contentLine += ki_queryBuilderCondition(prefix, condition['field'], condition['operator'], condition['value']);
		}
		content += '<li>' + contentLine + '</li>';
	}
	
	var out = '<select>';
	out += '<option ' + (boolOp == 'AND' ? 'selected' : '') + '>AND</option>';
	out += '<option ' + (boolOp == 'OR' ? 'selected' : '') + '>OR</option>';
	out += '<option ' + (boolOp == 'XOR' ? 'selected' : '') + '>XOR</option></select> &nbsp; ';
	out += '<button type="button" onclick="ki_queryBuilderAddCondition(this, \'' + prefix + '\');" title="Add Condition">✚</button> ';
	out += '<button type="button" onclick="ki_queryBuilderAddGroup(this, \'' + prefix + '\');" title="Add Group">✚⊆</button> &nbsp; ';
	if(!root)
		out += '<div>&nbsp;</div><button type="button" title="Delete" onclick="this.parentNode.parentNode.removeChild(this.parentNode);">❌</button>';
	out += '<ul>' + content	+ '</ul>';
	return out;
}

function ki_queryBuilderCondition(prefix, field, operator, value)
{
	var allFields = ki_querybuilder_fields[prefix];
	
	if(typeof(field)    === 'undefined') field = Object.keys(allFields)[0];
	if(typeof(operator) === 'undefined') operator = '';
	if(typeof(value)    === 'undefined') value = '';
	
	var out = '<select onchange="ki_queryBuilderUpdateOperator(this, \'' + prefix + '\');">';
	for(var alias in allFields)
	{
		if(allFields.hasOwnProperty(alias))
		{
			out += '<option' + (alias == field ? ' selected' : '') + '>' + alias + '</option>';
		}
	}
	
	out += '</select><select onchange="ki_queryBuilderUpdateInput(this, \'' + prefix + '\');">';
	out += ki_queryBuilderGenerateOperatorOptions(field, prefix, operator) + '</select>';
	var dataType = ki_querybuilder_fields[prefix][field]['dataType'];
	var inputDisplay = ki_queryBuilderCalculateInputDisplay(dataType, operator);
	var inputType = ki_queryBuilderCalculateInputType(dataType, operator);
	var checked = (inputType == 'checkbox' && value) ? ' checked' : '';
	out += '<input type="' + inputType + '" value="' + value + '" style="display:' + inputDisplay + ';"' + checked + '/>';
	out += '<div>&nbsp;</div>';
	out += '<button type="button" title="Delete" onclick="this.parentNode.parentNode.removeChild(this.parentNode);">❌</button>';
	return out;
}

function ki_queryBuilderGenerateOperatorOptions(alias, prefix, selected)
{
	if(typeof(selected) === 'undefined') selected = '';
	var type = ki_querybuilder_fields[prefix][alias]['dataType'];
	var nullable = ki_querybuilder_fields[prefix][alias]['nullable'] == 'YES';
	
	var ops = [];
	if(type == 'tinyint(1)')
	{
		ops = ['='];
	}else if((type.indexOf('int') !== -1) || (type.indexOf('date') !== -1)){
		ops = ['=','!=','<','<=','>','>='];
	}else{
		ops = ['=','!=','<','<=','>','>=','contains','does not contain','contained in','not contained in','matches regex',"doesn't match regex"];
	}
	if(nullable)
	{
		ops.push('is NULL');
		ops.push('is not NULL');
	}
	
	var html = '';
	for(var i = 0; i<ops.length; ++i)
	{
		var op =  ops[i];
		html += '<option' + (op == selected ? ' selected' : '') + '>' + op + '</option>';
	}
	return html;
}

function ki_queryBuilderUpdateOperator(field, prefix, selected)
{
	if(typeof(selected) === 'undefined') selected = '';
	var operator = field.nextElementSibling;
	var alias = $(field).val();
	operator.innerHTML = ki_queryBuilderGenerateOperatorOptions(alias, prefix, $(operator).val());
	ki_queryBuilderUpdateInput(operator, prefix);
}

function ki_queryBuilderUpdateInput(operator, prefix)
{
	operator = $(operator);
	var input = operator.next();
	var field = operator.prev();
	var alias = field.val();
	var op    = operator.val();
	var dataType = ki_querybuilder_fields[prefix][alias]['dataType'];

	input.css('display',ki_queryBuilderCalculateInputDisplay(dataType, op));
	input.attr('type',ki_queryBuilderCalculateInputType(dataType, op));
}

function ki_queryBuilderCalculateInputDisplay(dataType, op)
{
	if(op == 'is NULL' || op == 'is not NULL')
	{
		return 'none';
	}
	return 'inline-block';
}

function ki_queryBuilderCalculateInputType(dataType, op)
{
	if     (dataType == 'tinyint(1)'           ) return 'checkbox';
	else if(dataType.indexOf('int'     ) !== -1) return 'number';
	else if(dataType.indexOf('datetime') !== -1) return 'datetime-local';
	else if(dataType.indexOf('date'    ) !== -1) return 'date';
	return 'text';
}

function ki_queryBuilderAddCondition(btn, prefix)
{
	var newCond = document.createElement("LI");
	newCond.innerHTML = ki_queryBuilderCondition(prefix);
	btn.parentNode.lastChild.appendChild(newCond);
	$(newCond.firstChild).change();
}

function ki_queryBuilderAddGroup(btn, prefix)
{
	var newCond = document.createElement("LI");
	newCond.innerHTML = ki_queryBuilderGroup(prefix);
	btn.parentNode.lastChild.appendChild(newCond);
}

/**
* form: the form whose data will be serialized
* prefix: the ID prefix of the associated QueryBuilder
* outForm: If not supplied, put the output where the source form will be expecting it.
* 	If a form, create a new element in this form to recieve the output
* 	If a link, add a parameter to its href with the output
*/
function ki_queryBuilderSerialize(form, prefix, outForm)
{
	var show = [];
	$(form).children('.ki_showOrder').find('input:checked').each(function(){
		show.push($(this).val());
	});
	
	var sort = [];
	$(form).children('.ki_sorter').find('select').each(function(){
		var val = $(this).val();
		if(val != 'N')
		{
			var item = new Object();
			item['field'] = $(this).attr('name');
			item['direction'] = val;
			sort.push(item);
		}
	});
	
	var filter = ki_queryBuilderFilterRecurse($('#' + prefix + '_filter'));
	var out = new Object();
	out['fieldsToShow'] = show;
	out['sortOrder'] = sort;
	out['rootConditionGroup'] = filter;
	$('#' + prefix + '_filterResult').val(JSON.stringify(out));
	
	/*
	TODO: binary format
	
	number of shown fields, 12 bits
	IDs of shown fields, 12 bits each
	000000000000, if number of fields was odd

	number of sorted fields, 12 bits
	0000
	specified number of sorted fields:
		colID, 12 bits
		direction, 1 bit
		000

	root condition group starting with 0 under this spec:
		0 (group):
			colID, 12 bits
			opID, 4 bits
			value length in bytes, 8 bits
			value of specified length
		1 (condition):
			boolOpId, 2 bits
			number of conditions on this level, 6 bits
			recurse
	*/
	
}

function ki_queryBuilderFilterRecurse(obj)
{
	var out = new Object();
	var subItems    = obj.children('ul').children('li');
	var isGroup     = subItems.length > 0;
	var isCondition = obj.children('select').length == 2;
	if(isGroup)
	{
		out['boolOp'] = obj.children('select').val();
		var conditions = [];
		subItems.each(function(){
			conditions.push(ki_queryBuilderFilterRecurse($(this)));
		});
		out['conditions'] = conditions;
		return out;
	}else if(isCondition){
		var field = obj.children('select').first();
		var operator = field.next();
		var value = operator.next();
		out['field'] = field.val();
		out['operator'] = operator.val();
		out['value'] = (value.attr('type') == 'checkbox') ? value.is(':checked') : value.val();
		return out;
	}else{
		return null;
	}
}