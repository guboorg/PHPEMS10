<?php
namespace PHPEMS;
class app
{
	public $G;
	public $_user;

	public function __construct()
	{
		
		$this->ev = \PHPEMS\ginkgo::make('ev');
		$this->session = \PHPEMS\ginkgo::make('session');
		$this->tpl = \PHPEMS\ginkgo::make('tpl');
		$this->db = \PHPEMS\ginkgo::make('pepdo');
		$this->apps = \PHPEMS\ginkgo::make('apps','core');
		$this->user = \PHPEMS\ginkgo::make('user','user');
		$this->survey = \PHPEMS\ginkgo::make('survey','survey');
        $this->config = \PHPEMS\ginkgo::make('config','survey');
        $this->_user = $_user = $this->session->getSessionUser();
		$group = $this->user->getGroupById($_user['sessiongroupid']);
		if($group['groupid'] != 1 && $this->ev->url(2) != 'login')
		{
			if($this->ev->get('userhash'))
			exit(json_encode(array(
				'statusCode' => 300,
				"message" => "请您重新登录",
			    "callbackType" => 'forward',
			    "forwardUrl" => "index.php?core-master-login"
			)));
			else
			{
				header("location:index.php?core-master-login");
				exit;
			}
		}
        $this->tpl->assign('selectors',$this->config->selectors);
		$user = $this->user->getUserById($_user['sessionuserid']);
		$user['manager_apps'] = unserialize($user['manager_apps']);
		if(!is_array($user['manager_apps']))
		{
			$user['manager_apps'] = array();
		}
		$this->tpl->assign('_user',$user);
		$this->tpl->assign('action',$this->ev->url(2)?$this->ev->url(2):'user');
		$localapps = $this->apps->getLocalAppList();
		$apps = $this->apps->getAppList();
		$this->tpl->assign('localapps',$localapps);
		$this->tpl->assign('apps',$apps);
        $this->tpl->assign('userhash',$this->ev->get('userhash'));
    }
}

?>