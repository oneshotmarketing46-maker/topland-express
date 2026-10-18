<?php
// Topland Express form endpoint. No visitor data or attachments are saved publicly.
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Lax']);
session_start();
function reply(int $code, string $message, array $extra=[]): void {
 http_response_code($code); echo json_encode(array_merge(['ok'=>$code===200,'message'=>$message],$extra)); exit;
}
if ($_SERVER['REQUEST_METHOD']==='GET') {
 if(empty($_SESSION['form_token'])) $_SESSION['form_token']=bin2hex(random_bytes(32));
 reply(200,'Ready',['token'=>$_SESSION['form_token']]);
}
if ($_SERVER['REQUEST_METHOD']!=='POST') {header('Allow: GET, POST');reply(405,'Method not allowed.');}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>7*1024*1024) reply(413,'Please keep all attachments together under 5 MB.');
$origin=parse_url($_SERVER['HTTP_ORIGIN']??'',PHP_URL_HOST);
if ($origin && strtolower($origin)!==strtolower(explode(':',$_SERVER['HTTP_HOST'])[0])) reply(403,'Please submit from our website.');
$token=$_POST['token']??'';
if(!is_string($token)||empty($_SESSION['form_token'])||!hash_equals($_SESSION['form_token'],$token)) reply(403,'Please refresh the page and try again.');
function field(string $key,int $limit=300): string {
 $v=$_POST[$key]??'';
 if(!is_string($v)||strlen($v)>$limit) reply(422,'Please check the length of your form fields.');
 return trim(str_replace("\0",'', $v));
}
if(field('website')!=='') reply(422,'Unable to accept this submission.');
$type=field('form_type');
if(!in_array($type,['quote','career'],true)) reply(422,'Unknown form.');
$email=field('email');
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email)) reply(422,'Enter a valid email address.');
$keys=$type==='quote'?['name','company','phone','email','origin','destination','freight','message']:['name','phone','email','city','position','licence','experience','start','message','consent'];
$required=$type==='quote'?['name','phone','origin','destination']:['name','phone','position','consent'];
$data=[];
foreach($keys as $key) {$data[$key]=field($key,$key==='message'?6000:300);if(in_array($key,$required,true)&&$data[$key]==='') reply(422,'Please complete all required fields.');}
if($type==='career'&&$data['consent']!=='yes') reply(422,'Please confirm your consent.');
$attachments=[];$total=0;
$allowed=['pdf'=>['application/pdf'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'doc'=>['application/msword','application/x-ole-storage','application/CDFV2'],'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip']];
foreach($_FILES as $key=>$file) {
 if(!in_array($key,['licenceFile','resumeFile'],true)||$type!=='career') reply(422,'Unexpected attachment.');
 if(!is_array($file)||!isset($file['error'])||is_array($file['error'])) reply(422,'Invalid attachment.');
 if($file['error']===UPLOAD_ERR_NO_FILE) continue;
 if($file['error']!==UPLOAD_ERR_OK) reply(422,'Attachment upload failed. Please choose a smaller file.');
 $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
 $valid=$key==='licenceFile'?['pdf','jpg','jpeg','png']:['pdf','doc','docx'];
 if(!in_array($ext,$valid,true)||!is_uploaded_file($file['tmp_name'])) reply(422,'Please upload a supported document type.');
 $size=filesize($file['tmp_name']);$total+=$size;
 if($size<1||$total>5*1024*1024) reply(422,'All attachments together must be under 5 MB.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
 if(!in_array($mime,$allowed[$ext],true)) reply(422,'The attachment content does not match its file type.');
 $attachments[]=['name'=>($key==='licenceFile'?'licence':'resume').'.'.$ext,'mime'=>$mime,'body'=>chunk_split(base64_encode(file_get_contents($file['tmp_name'])))];
}
// Limit each IP to five accepted attempts per hour, across browser sessions.
$ratePath=sys_get_temp_dir().'/topland-form-'.hash('sha256',__DIR__.($_SERVER['REMOTE_ADDR']??'')).'.json';
$lock=fopen($ratePath,'c+');
if(!$lock||!flock($lock,LOCK_EX)) reply(503,'Sending is temporarily unavailable. Please call 905 977 1313.');
$times=json_decode(stream_get_contents($lock),true);$now=time();
$times=is_array($times)?array_values(array_filter($times,function($t)use($now){return is_int($t)&&$t>$now-3600;})):[];
if(count($times)>=5){flock($lock,LOCK_UN);fclose($lock);reply(429,'Too many requests. Please try again later or call 905 977 1313.');}
$times[]=$now;rewind($lock);ftruncate($lock,0);fwrite($lock,json_encode($times));flock($lock,LOCK_UN);fclose($lock);
$subject=$type==='quote'?'Topland Express - New quote request':'Topland Express - New career application';
$text=$subject."\r\n\r\n";
foreach($data as $key=>$value) $text.=ucfirst($key).": ".$value."\r\n";
$text.="\r\nSubmitted at: ".gmdate('c')."\r\n";
$boundary='topland_'.bin2hex(random_bytes(16));
$headers=['From: Topland Express Website <accounts@toplandexpress.com>','Reply-To: '.$email,'MIME-Version: 1.0','Content-Type: multipart/mixed; boundary="'.$boundary.'"'];
// Construct attachment parts using CRLF for mail transport.
$body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text));
foreach($attachments as $a) $body.='--'.$boundary."\r\nContent-Type: ".$a['mime'].'; name="'.$a['name']."\"\r\nContent-Disposition: attachment; filename=\"".$a['name']."\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".$a['body'];
$body.='--'.$boundary."--\r\n";
if(!function_exists('mail')||!mail('accounts@toplandexpress.com',$subject,$body,implode("\r\n",$headers),'-faccounts@toplandexpress.com')) reply(503,'We could not send your request. Please try again or email accounts@toplandexpress.com.');
reply(200,'Your request has been submitted. Thank you.');
