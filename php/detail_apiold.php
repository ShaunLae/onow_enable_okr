<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$userId=(int)$_SESSION['user_id'];$role=$_SESSION['user_role'];
if($role==='Admin'){echo json_encode(['error'=>'No access']);exit;}
$id=(int)($_GET['id']??0);
$obj=getObjective($id);
if(!$obj){echo json_encode(['error'=>'Not found']);exit;}
$krs=getKeyResults($id);$members=getObjectiveMembers($id);$attachments=getAttachments($id);
$canEdit=($role==='Manager'&&$obj['created_by']==$userId)||($role==='Member'&&$obj['owner_id']==$userId);
$obj['status_class']=statusClass($obj['status']);$obj['can_edit']=$canEdit;$obj['progress']=(float)$obj['progress'];
foreach($krs as &$kr){$kr['status_class']=statusClass($kr['status']);$kr['progress']=(float)$kr['progress'];$kr['current_value']=(float)$kr['current_value'];$kr['target_value']=(float)$kr['target_value'];$kr['owner_id']=(int)$kr['owner_id'];}
foreach($attachments as &$a){$a['icon']=fileIcon($a['original_name']);$a['size_fmt']=fmtSize((int)$a['file_size']);}
echo json_encode(['objective'=>$obj,'key_results'=>$krs,'members'=>$members,'attachments'=>$attachments]);
