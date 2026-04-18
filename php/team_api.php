<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$userId=(int)$_SESSION['user_id'];$role=$_SESSION['user_role'];
if($role==='Admin'){echo json_encode(['success'=>false,'message'=>'No access']);exit;}
$teamId=(int)($_GET['team_id']??0);
if(!$teamId){echo json_encode(['success'=>false,'message'=>'team_id required']);exit;}
$myTeamIds=array_column(getUserTeams($userId),'id');
if(!in_array($teamId,$myTeamIds)){echo json_encode(['success'=>false,'message'=>'Not your team']);exit;}
echo json_encode(['success'=>true,'members'=>getTeamMembersForObjective($teamId,$userId),'current_user'=>['id'=>$userId,'full_name'=>$_SESSION['user_name'],'role'=>$role]]);
