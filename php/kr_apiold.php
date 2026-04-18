<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$userId=(int)$_SESSION['user_id'];$role=$_SESSION['user_role'];
if($role==='Admin'){echo json_encode(['success'=>false,'message'=>'No access']);exit;}

if($_SERVER['REQUEST_METHOD']==='GET'){
    if(isset($_GET['obj_members'])){echo json_encode(getObjectiveMembers((int)($_GET['objective_id']??0)));exit;}
    if(isset($_GET['objective_id'])){echo json_encode(getKeyResults((int)$_GET['objective_id']));exit;}
    if(isset($_GET['history'])){echo json_encode(getProgressHistory((int)($_GET['kr_id']??0)));exit;}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!validateCsrf($_POST['csrf_token']??'')){echo json_encode(['success'=>false,'message'=>'Invalid CSRF']);exit;}
    $action=$_POST['action']??'';
    if($action==='create_kr'){
        if(empty($_POST['title'])||empty($_POST['objective_id'])){echo json_encode(['success'=>false,'message'=>'Title required']);exit;}
        if((float)($_POST['target_value']??0)<=0){echo json_encode(['success'=>false,'message'=>'Target must be > 0']);exit;}
        if($role==='Member')$_POST['owner_id']=$userId;
        echo json_encode(createKeyResult($_POST,$userId));exit;
    }
    if($action==='update_kr'){
        if($role==='Member'){echo json_encode(['success'=>false,'message'=>'Members cannot edit KR details']);exit;}
        echo json_encode(updateKeyResult((int)($_POST['id']??0),$_POST,$userId));exit;
    }
    if($action==='delete_kr'){
        if($role==='Member'){echo json_encode(['success'=>false,'message'=>'Members cannot delete KRs']);exit;}
        echo json_encode(deleteKeyResult((int)($_POST['id']??0),$userId));exit;
    }
    if($action==='update_progress'){
        $krId=(int)($_POST['kr_id']??0);
        if($role==='Member'){
            $kr=getKeyResult($krId);
            if(!$kr||$kr['owner_id']!=$userId){echo json_encode(['success'=>false,'message'=>'You can only update KRs assigned to you']);exit;}
        }
        echo json_encode(updateKRProgress($krId,(float)($_POST['new_value']??0),$_POST['note']??'',$userId));exit;
    }
}
echo json_encode(['success'=>false,'message'=>'Invalid request']);
