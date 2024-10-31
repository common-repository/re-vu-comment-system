 <?php 
			$revu=get_option('kampyle');
			//print_r($revu);
			echo $revu['head'];
		
   ?>
    <div id="rev_images">&nbsp;</div> 
<script src="http://www.re-vu.com/json2.js"></script>
<script src="http://code.jquery.com/jquery-latest.js"></script>

 <script type="text/javascript">
 <!--//
function sizeFrame(frameId) {
var F = document.getElementById(frameId);

if(F.contentDocument) {

//F.contentDocument.documentElement.scrollHeight+30
F.height = F.contentWindow.document.body.scrollHeight+30; //FF 3.0.11, Opera 9.63, and Chrome

} else {

F.height = F.contentWindow.document.body.scrollHeight+30; //IE6, IE7 and Chrome

}

}

// window.onload=sizeFrame;

//-->
// frameID test
var comment_id=0;
function requestCrossDomain() {
	
var url=window.location.href;


//revsys_shortname + "&revsys_identifier=" +url + "&title=" +title+ "&re_width=" +revu_width;
//alert('alok');
//alert(revsys_shortname);

//var url=window.location.pathname;
url=url.replace(/\//g,'@');
var check_title=document.getElementsByTagName("title").length;
if (check_title>0){
	var title='';
	var title = document.getElementsByTagName("title")[0].innerHTML;
	title= title.split(' ').join('-');
	title= title.replace(/[^a-zA-Z 0-9]+/g,'-');

}

//var revsys_shortname="alokfuterox";
var innerhtml="";
var reviewurl='http://re-vu.com/ws.php?comment_id='+comment_id+'&title='+title;
// Take the provided url, and add it to a YQL query. Make sure you encode it! json&diagnostics=true&callback=re_comment
	var yql = 'http://query.yahooapis.com/v1/public/yql?q=' + encodeURIComponent('select * from html where url="' + reviewurl + '" and  xpath="//p"') + '&format=xml&callback=?';
	// Request that YSQL string, and run a callback function.
	// Pass a defined function to prevent cache-busting.
	$.getJSON( yql, cbFunc );
	
	function cbFunc(data) {
	// If we have something to work with...
	if ( data.results[0] ) {
		// Strip out all script tags, for security reasons.
		// BE VERY CAREFUL. This helps, but we should do more. 
		data = data.results[0].replace(/<[\/]{0,1}(P|p)[^><]*>/g, '');
		re_obj = JSON.parse(data);
		
 		if (comment_id!=0){
		var temp = document.getElementById("rev_images");
		innerhtml+='<div id="review_comment'+re_obj.review_id+'" class=""><div class="main_comt_cont"><div class="smallbox" style="clear:both; width:600px;"><div class="revu_comments"><div class="smallimage"><img src="'+re_obj.user_pic+'"></div><div id="myDiv"></div><div class="thelanguage" contentindex="0c"><div class="smalltxt"><div class="smallt"><span class="rating_'+re_obj.review_rating+'"></span></div> </div>  <div style="font-size:12px; font-weight:bold; color:#000; float:left; margin-top:4px; height:24px;"><span>by <a href="http://re-vu.com/users/myreviews" target="_blank">'+re_obj.user_username+'</a></span></div><div class="clr"></div><div style="margin-top:5px; padding:0px 15px 15px 0; font-size:11px; color:#666666;" class="fr"><div class="revu_addpic" style="width:46px;"><a href="http://www.addthis.com/bookmark.php" style="text-decoration:none; float:left;" class="addthis_button">Share</a><span></span></div><div class="clr"></div></div><div style="width:100%; float:left; margin:20px 0 35px;">';
		
		if(re_obj.remote_review_image!=null){
		innerhtml+='<div style="float:left; margin-right:10px; width:75px; height:75px;"><img width="75" src="'+re_obj.remote_review_image+'"></div>';
		}
		innerhtml+='<div class="coment_section">'+re_obj.review_body+'</div></div><div class="clr"></div></div></div></div></div> <div class="bor_btom"></div></div>';
		//alert();
		//$("#rev_images").prepend(innerhtml);
		var save_temp = temp.innerHTML;
		temp.innerHTML=innerhtml+save_temp;
		}
		comment_id=re_obj.review_id;
		
	}
	// Else, Maybe we requested a site that doesn't exist, and nothing returned.
	//else throw new Error('Nothing returned from getJSON.');
	}
	var t=setTimeout("requestCrossDomain()",3000);
}


requestCrossDomain();
var _revu_api_base_url="http://www.re-vu.com/users/"

function _upAndDown(_revuCommentId,_thumbs) {
	$.getJSON(_revu_api_base_url + 'upand_down/?callback=?',
	          { '_revuCommentId' :  _revuCommentId ,'_thumbs' : _thumbs },
		      function(response) {
		          if (response.revu_status == 'ok') {
					 alert(response.revu_comment_status);
		          }else{
					  alert("Please login to send your feedback.")
					  }
		      });
	}



 </script>
 
  
