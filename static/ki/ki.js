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