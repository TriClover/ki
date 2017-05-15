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