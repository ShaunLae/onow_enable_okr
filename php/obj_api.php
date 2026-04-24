<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$userId=(int)$_SESSION['user_id']; $role=$_SESSION['user_role'];
if($role==='Admin'){echo json_encode(['success'=>false,'message'=>'Admins do not manage OKRs.']);exit;}

if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['get'])){
    $o=getObjective((int)($_GET['id']??0));
    if($o){$o['member_ids']=array_column(getObjectiveMembers($o['id']),'id');echo json_encode($o);}
    else echo json_encode(['error'=>'Not found']);exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!validateCsrf($_POST['csrf_token']??'')){echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);exit;}
    $action=$_POST['action']??'';
    if($action==='create'){
        if($role==='Member')$_POST['type']='Personal';
        echo json_encode(createObjective($_POST,$userId));exit;
    }
    if($action==='update'){
        $id=(int)($_POST['id']??0);$o=getObjective($id);
        if(!$o){echo json_encode(['success'=>false,'message'=>'Not found']);exit;}
        if($role==='Member'){if($o['owner_id']!=$userId){echo json_encode(['success'=>false,'message'=>'Not authorised']);exit;}$_POST['type']='Personal';}
        if($role==='Manager'&&$o['created_by']!=$userId){echo json_encode(['success'=>false,'message'=>'Not authorised']);exit;}
        echo json_encode(updateObjective($id,$_POST,$userId));exit;
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);$o=getObjective($id);
        if(!$o){echo json_encode(['success'=>false,'message'=>'Not found']);exit;}
        if($role==='Member'&&$o['owner_id']!=$userId){echo json_encode(['success'=>false,'message'=>'Not authorised']);exit;}
        if($role==='Manager'&&$o['created_by']!=$userId){echo json_encode(['success'=>false,'message'=>'Not authorised']);exit;}
        echo json_encode(deleteObjective($id,$userId));exit;
    }
    if($action==='upload_attachment'){
        $objId=(int)($_POST['objective_id']??0);
        if(!$objId||empty($_FILES['attachment'])){echo json_encode(['success'=>false,'message'=>'No file']);exit;}
        $o=getObjective($objId);
        if(!$o){echo json_encode(['success'=>false,'message'=>'Objective not found']);exit;}
        if($role==='Member' && (int)$o['owner_id']!==$userId){echo json_encode(['success'=>false,'message'=>'Not authorised']);exit;}
        if($role==='Manager' && (int)$o['created_by']!==$userId){echo json_encode(['success'=>false,'message'=>'Not authorised']);exit;}
        echo json_encode(saveAttachment($objId,$_FILES['attachment'],$userId));exit;
    }
    if($action==='delete_attachment'){
        $attId=(int)($_POST['attachment_id']??0);
        if(!$attId){echo json_encode(['success'=>false,'message'=>'Attachment not found']);exit;}
        $db=getDB();
        $s=$db->prepare("SELECT objective_id FROM objective_attachments WHERE id=?");
        $s->execute([$attId]);
        $att=$s->fetch();
        if(!$att){echo json_encode(['success'=>false,'message'=>'Attachment not found']);exit;}
        $o=getObjective((int)$att['objective_id']);
        if(!$o){echo json_encode(['success'=>false,'message'=>'Objective not found']);exit;}
        if($role==='Member' && (int)$o['owner_id']!==$userId){echo json_encode(['success'=>false,'message'=>'Not authorised']);exit;}
        if($role==='Manager' && (int)$o['created_by']!==$userId){echo json_encode(['success'=>false,'message'=>'Not authorised']);exit;}
        echo json_encode(deleteAttachment($attId));exit;
    }
}
echo json_encode(['success'=>false,'message'=>'Invalid request']);
