<?
#v1.07

#define DSN,USER,PASS
if(!defined('PATH')) define('PATH',dirname(dirname(__FILE__)).'/');
if(!defined('STREAMS')) define('STREAMS',40);

class Tpl{#Template engine
	public $layout;
	function __construct(){
		#path to sceleton template, place <?=$tpl->body things in it
		$this->layout=PATH.'core/tpl/layout.php';
	}
	#example: $tpl->put('body','main',getMain());
	#getMain has to return array of variables
	#template function tplMain shows content into $tpl->body
	public function put($where,$what,array $params=array()){
		$this->$where.=$this->get($what,$params);
	}
	public function get($what,array $params=array()){
		ob_start();
		call_user_func_array('tpl'.ucfirst($what),$params);
		return ob_get_clean();
	}
	#render everything. call at the end or from __destruct()
	function render(){
		ob_start();
		@include $this->layout;
		echo preg_replace('![ \t]+!',' ',ob_get_clean());
	}
}

#make your urls readable
function str2url($str){
	$str2=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$str);
	$str=$str2?:$str;
	$str=trim(strtolower($str));
	return preg_replace(array('!\s+!','![^\w\d\-\.\']!','!-+!'),array('-','','-'),$str);
}

#PDO-based db class with error handling and functional-style calls for short code
class DB{
	var $db;
	var $dbType;
	function __construct($dsn='',$user=null,$pass=null){
		global $db;
		$dsn=$dsn?:DSN;
		$user=$user!==null?:USER;
		$pass=$pass!==null?:PASS;
		$this->db=new PDO($dsn,$user,$pass) or die("error connecting to db\n");
		$this->dbType=preg_replace('!^(.*?):.*$!','$1',$dsn);
		if(!$db){
			$db=$this->db;
		}		
	}
	function q($qs){
		$q=$this->db->query($qs);
		if($er=@$this->db->errorInfo()[2] or $er=@$q->errorInfo()[2]){
			lg("PDO: $qs: $er");
			echo ob_get_clean();die;
		}
		return $q;
	}
	function quote($var){ return $this->db->quote($var); }
	function &fa($qs){
		$q=$this->q($qs);
		$items=[];
		while($d=$q->fetch(5)){
			$items[]=$d;
		}
		return $items;
	}
	function prepQ($o){
		$q=[];
		$delim=$this->dbType=='mysql'?'`':"'";
		foreach($o as $k=>$v){
			$q[]="$delim$k$delim=".$this->quote($v);
		}
		return implode(',',$q);
	}
}
function q($qs){
	global $customDb; if(empty($customDb))$customDb=new DB();
	return $customDb->q($qs);
}
function quote($var){
	global $customDb; if(empty($customDb))$customDb=new DB();
	return $customDb->quote($var);
}
function &fa($qs){
	global $customDb; if(empty($customDb))$customDb=new DB();
	return $customDb->fa($qs);
}
function prepQ($o){
	global $customDb; if(empty($customDb))$customDb=new DB();
	return $customDb->prepQ($o);
}
#/db

function lg($err){#log
    trigger_error($err,E_USER_WARNING);
}
function go($where,$code=0){#redirect
	if($where===404){$where="/404.html";$code=302;}
    header("Location: $where",0,$code?:301);
    die;		
}

function setLinks($txt,$urls){#insert <a> tags into text
	$pattern='<a href="%1$s" title="%2$s">%2$s</a>';#%1$s - url, %2$s - title
	$ar=[];
	foreach($urls as $u){
		$ar[$u->url]=$u->title;
	}
	uasort($ar,function ($a,$b){return strlen($a)<strlen($b);});
	$hash2u=[];
	foreach($ar as $u=>$t){
		$hash='<'.md5(rand(0,100000)).'>';
		$txt=preg_replace('/(\W)'.preg_quote($t,'/').'(\W)/usi',"$1$hash$2",$txt,1);
		$hash2u[$hash]=(object)['url'=>$u,'title'=>$t];
	}
	foreach($hash2u as $hash=>$u){
		$txt=str_replace($hash,sprintf($pattern,$u->url,$u->title),$txt);
	}
	return $txt;
}

#curl and phantomJS wrapper for various crawlers
class WebGet{
	#var $tries=3;
	var $timeout=15;
	var $debugFn='/tmp/1.html';
	var $post=[];
	var $cookie=[];
	var $headers=[];
	var $ref='';
	var $ua='';
	private $havePhantom = 0;
	function __construct(){
		if(defined('WGET_TRIES')) $this->tries=WGET_TRIES;
		if(defined('WGET_TIMEOUT')) $this->timeout=WGET_TIMEOUT;
	}
	function ua(){
        static $uas=[
            'Ze-robot v0.1',
        ];
		return $this->ua?:$uas[rand(0,count($uas)-1)];
	}
	function &get($url,$proxy='',$debug=0){
		$tmpCookie=tempnam('/tmp/','webGetCookie_');
		$c=curl_init($url);
		$opts=[
			CURLOPT_USERAGENT=>$this->ua(),
			CURLOPT_AUTOREFERER=>1,
			CURLOPT_COOKIEFILE=>$tmpCookie,
			CURLOPT_COOKIEJAR=>$tmpCookie,
			CURLOPT_RETURNTRANSFER=>1,
			CURLOPT_TIMEOUT=>$this->timeout,
			CURLOPT_MAXREDIRS=>10,
			CURLOPT_VERBOSE=>(bool)$debug,
			CURLOPT_HEADER=>(bool)$debug,
			CURLINFO_HEADER_OUT=>(bool)$debug,
			CURLOPT_FOLLOWLOCATION=>1,
		];
		if($proxy){
			$opts[CURLOPT_HTTPPROXYTUNNEL]=1;
			$opts[CURLOPT_PROXY]=$proxy;
		}
		if($this->post){
			$opts[CURLOPT_POST]=1;
			$opts[CURLOPT_POSTFIELDS]=$this->post;
			#$opts[CURLOPT_SAFE_UPLOAD]=1;#php 5.6+
		}
		if($this->ref){
			$opts[CURLOPT_REFERER]=$this->ref;
		}
		if($this->cookie){
			$opts[CURLOPT_COOKIE]=http_build_query($this->cookie,'','; ');
		}
		if($this->headers){
			$tmp=[];foreach($this->headers as $n=>$v)$tmp[]="$n: $v";
			$opts[CURLOPT_HTTPHEADER]=$tmp;
		}
		curl_setopt_array($c,$opts);
		if($debug!=2)
			$str=curl_exec($c);
		if($debug==1){
			echo strlen($str).' symb got ';
			file_put_contents($this->debugFn,$str);#debug
			var_dump(curl_getinfo($c));
		}elseif($debug==2)
			$str=file_get_contents($this->debugFn);
		if(!unlink($tmpCookie))
			echo "file $tmpCookie not removed\n";
		$page=new WebGetPage($str,curl_getinfo($c,CURLINFO_HTTP_CODE));
		curl_close($c);
		return $page;
	}
	function &getJS($url,$proxy='',$debug=0){
		$this->checkPhantom();
		$scriptFn=PATH.'core/phantomGet.js';
		$ua = escapeshellarg($this->ua());
		$urlq = escapeshellarg($url);
		$proxyq = $proxy?' --proxy='.escapeshellarg($proxy).' ':'';
		$res=`phantomjs $proxyq $scriptFn $urlq $ua`;
		$res=explode("\n",$res,2);
		if(count($res)>1)
			list($code,$html)=$res;
		else
			list($code,$html)=[0,$res[0]];
		$page=new WebGetPage($html,$code);
		return $page;
	}
	private function checkPhantom(){
		$flag = &$this->havePhantom;
		if(!$flag){
			$whe = `whereis phantomjs`;
			$flag = (trim($whe)=='phantomjs:'?-1:1);
		}
		if($flag == -1){
			echo "install phantom js\n";
			die("install phantom js\n");
		}
	}
}
class WebGetPage{
	var $page='';
	var $code;
	function __construct(&$page,$code=0){
		$this->page=$page;
		$this->code=$code;
	}
	function __toString(){return ''.$this->page;}
}
function &wget($url,$proxy='',$debug=0){
	global $WebGet;
	if(!$WebGet) $WebGet=new WebGet();
	return $WebGet->get($url,$proxy,$debug);
}
function &wgetJS($url,$proxy=''){
	global $WebGet;
	if(!$WebGet) $WebGet=new WebGet();
	return $WebGet->getJS($url,$proxy);
}

#multistreams in php though linux shell
function stream($lnch,$param,$streams){
	static $num=0;
	while($num>=$streams){
		sleep(1);
		$num=(int)shell_exec("ps aux|grep '$lnch'|grep -v grep|wc -l");
		echo "$num ";
	}
	#$er='2>/dev/null';
	$er='2>>err.log';
	shell_exec("nohup $lnch $param >/dev/null $er &");$num++;
}

#proxy 
#get a proxy that is ok
function getProxy(){
	if(!is_dir(PATH.'proxy/inUse')){
		if(!is_dir(PATH.'proxy'))
			mkdir(PATH.'proxy') and chmod(PATH.'proxy',0777);
		mkdir(PATH.'proxy/inUse') and chmod(PATH.'proxy/inUse',0777);
	}
	$fn=PATH.'proxy/proxy';
	$prxs=file($fn,FILE_SKIP_EMPTY_LINES);
	shuffle($prxs);
	while(1){
		foreach($prxs as &$prx){
			$prx=rtrim($prx);
			if(@file_get_contents(PATH.'proxy/inUse/'.$prx)<time())
				break(2);
		}
		echo "there is no free proxy. waiting.\n";
		sleep(1);
	}
	rateProxy($prx,-1);
	return $prx;
}

/*set current status of the proxy
one NEED to call this function after parsing download result
if download was successful, $set is 1 else $set is 0*/
function rateProxy($prx,$set){
	$waits=array(-1=>600,120,3);#in use,punish for bad,rest for good
	$wait=$waits[$set];
	$t=time()+$wait;
	file_put_contents(PATH.'proxy/inUse/'.$prx,$t);
}

#automatic translation
#SET $clientSecret!!
function translate($txt){
	$txt=html_entity_decode(strip_tags($txt));

	$from='de';$to='en';
	$clientID="13dagger_s_MSTranslator";
	$clientSecret=urlencode("");#!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
	if(!$clientSecret) die("set $clientSecret to translate\n");
	$scopeUrl=urlencode("http://api.microsofttranslator.com");
	$paramArr="grant_type=client_credentials&scope=$scopeUrl&client_id=$clientID&client_secret=$clientSecret";
	$ch = curl_init("https://datamarket.accesscontrol.windows.net/v2/OAuth2-13/");
	curl_setopt($ch,CURLOPT_POST,1);
	curl_setopt($ch,CURLOPT_POSTFIELDS,$paramArr);
	curl_setopt ($ch,CURLOPT_RETURNTRANSFER,1);
	curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,0);

	$token=json_decode(curl_exec($ch))->access_token;
		
	$url= "http://api.microsofttranslator.com/v2/Http.svc/Translate?text=".urlencode($txt)."&from=$from&to=$to";
	curl_setopt($ch,CURLOPT_URL,$url);
	curl_setopt($ch,CURLOPT_HTTPHEADER, array("Authorization: Bearer $token","Content-Type: text/xml"));
	curl_setopt($ch,CURLOPT_HTTPGET,1);
	
	$cr=curl_exec($ch);
	$res=trim(strip_tags($cr));
	curl_close($ch);
	return $res;
}

