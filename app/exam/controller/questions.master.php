<?php
 namespace PHPEMS;
/*
 * Created on 2016-5-19
 *
 * To change the template for this generated file go to
 * Window - Preferences - PHPeclipse - PHP - Code Templates
 */
class action extends app
{
	public function display()
	{
		$action = $this->ev->url(3);
		if(!method_exists($this,$action))
		$action = "index";
		$search = $this->ev->get('search');
		$this->u = '';
		if($search)
		{
			$this->tpl->assign('search',$search);
			foreach($search as $key => $arg)
			{
				$this->u .= "&search[{$key}]={$arg}";
			}
		}
		$this->tpl->assign('u',$this->u);
		$this->$action();
		exit;
	}

	private function makequery()
	{
		$message = array(
			"statusCode" => 200,
			"message" => "操作成功，正在转入查询结果",
			"callbackType" => "forward",
		    "forwardUrl" => "index.php?exam-master-questions{$u}"
		);
		\PHPEMS\ginkgo::R($message);
	}

	private function filebataddquestion()
	{
		setlocale(LC_ALL,'zh_CN');
		if($this->ev->get('insertquestion'))
		{
			$page = $this->ev->get('page');
			$uploadfile = trim($this->ev->get('uploadfile'));
			$uploadfile = $this->exam->resolveImportCsvFile($uploadfile);
			$knowsid = trim($this->ev->get('knowsid'));
			if(!$uploadfile)
			{
				$message = array(
					'statusCode' => 300,
					"message" => "请先上传有效的CSV文件"
				);
				\PHPEMS\ginkgo::R($message);
			}
			$this->exam->importQuestionBat($uploadfile,$knowsid);
			$message = array(
				'statusCode' => 200,
				"message" => "操作成功",
				"callbackType" => "forward",
			    "forwardUrl" => "index.php?exam-master-questions&page={$page}{$this->u}"
			);
			\PHPEMS\ginkgo::R($message);
		}
		else
		$this->tpl->display('question_filebatadd');
	}

	private function deepseekimport()
	{
		if($this->ev->get('importquestion'))
		{
			$page = $this->ev->get('page');
			$apiKey = trim($this->ev->get('api_key'));
			$model = trim($this->ev->get('model'));
			$prompt = trim($this->ev->get('prompt'));
			$knowsid = trim($this->ev->get('knowsid'));
			$count = intval($this->ev->get('count'));
			$questiontype = intval($this->ev->get('questiontype'));
			$questionlevel = intval($this->ev->get('questionlevel'));
			$maxTokens = intval($this->ev->get('max_tokens'));
			if(!$model)$model = 'deepseek-v4-flash';
			if($count <= 0)$count = 5;
			if($maxTokens <= 0)$maxTokens = 4096;
			if(!$apiKey || !$prompt)
			{
				$message = array('statusCode' => 300,"message" => "请填写 DeepSeek API Key 和生成要求");
				\PHPEMS\ginkgo::R($message);
			}
			$systemPrompt = '你是 PHPEMS 试题生成助手。请严格输出 json 对象，不要输出 Markdown。json 格式：{"questions":[{"type":1,"question":"题干","options":["A. 选项","B. 选项"],"select_number":4,"answer":"A","analysis":"答案解析","level":1,"knowsid":"1,2"}]}。type 为题型ID，level 为1到5整数，options 可为空数组。';
			$userPrompt = "请根据以下要求生成 {$count} 道试题，必须包含题目选项、题目难易度、题型、答案和答案解析。默认题型ID：{$questiontype}，默认难度：{$questionlevel}，默认知识点ID：{$knowsid}。请返回 json。\n\n".$prompt;
			if(!function_exists('curl_init'))
			{
				$message = array('statusCode' => 300,"message" => "服务器未启用 PHP curl 扩展，无法调用 DeepSeek API");
				\PHPEMS\ginkgo::R($message);
			}
			$payload = array(
				'model' => $model,
				'messages' => array(
					array('role' => 'system','content' => $systemPrompt),
					array('role' => 'user','content' => $userPrompt)
				),
				'response_format' => array('type' => 'json_object'),
				'max_tokens' => $maxTokens,
				'temperature' => 0.7
			);
			$ch = curl_init('https://api.deepseek.com/chat/completions');
			curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
			curl_setopt($ch,CURLOPT_POST,true);
			curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-Type: application/json','Authorization: Bearer '.$apiKey));
			curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload));
			curl_setopt($ch,CURLOPT_TIMEOUT,120);
			$response = curl_exec($ch);
			$error = curl_error($ch);
			$httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE);
			curl_close($ch);
			if($error || $httpCode < 200 || $httpCode >= 300)
			{
				$message = array('statusCode' => 300,"message" => "DeepSeek 调用失败：".($error?$error:$response));
				\PHPEMS\ginkgo::R($message);
			}
			$result = json_decode($response,true);
			if(!$result || !is_array($result))
			{
				$message = array('statusCode' => 300,"message" => "DeepSeek 返回内容不是有效 JSON");
				\PHPEMS\ginkgo::R($message);
			}
			if(isset($result['error']['message']))
			{
				$message = array('statusCode' => 300,"message" => "DeepSeek 调用失败：".$result['error']['message']);
				\PHPEMS\ginkgo::R($message);
			}
			$content = isset($result['choices'][0]['message']['content'])?$result['choices'][0]['message']['content']:'';
			if(!$content)
			{
				$message = array('statusCode' => 300,"message" => "DeepSeek 未返回试题内容，请减少生成数量或提高最大输出 Token");
				\PHPEMS\ginkgo::R($message);
			}
			$data = json_decode($content,true);
			if(!$data || !is_array($data['questions']))
			{
				$message = array('statusCode' => 300,"message" => "DeepSeek 未返回有效的试题 json");
				\PHPEMS\ginkgo::R($message);
			}
			$imported = 0;
			foreach($data['questions'] as $question)
			{
				$args = array();
				$options = isset($question['options'])?$question['options']:array();
				$args['questiontype'] = isset($question['type']) && intval($question['type'])?intval($question['type']):$questiontype;
				$args['question'] = $this->ev->addSlashes(htmlspecialchars(trim(isset($question['question'])?$question['question']:'')));
				if(is_array($options))$args['questionselect'] = $this->ev->addSlashes(htmlspecialchars(implode("\n",$options)));
				else $args['questionselect'] = $this->ev->addSlashes(htmlspecialchars(trim($options)));
				$args['questionselectnumber'] = isset($question['select_number']) && intval($question['select_number'])?intval($question['select_number']):(is_array($options)?count($options):0);
				$args['questionanswer'] = $this->ev->addSlashes(htmlspecialchars(trim(isset($question['answer'])?$question['answer']:'')));
				$args['questiondescribe'] = $this->ev->addSlashes(htmlspecialchars(trim(isset($question['analysis'])?$question['analysis']:'')));
				$args['questionlevel'] = isset($question['level']) && intval($question['level'])?intval($question['level']):$questionlevel;
				$args['questioncreatetime'] = TIME;
				$args['questionuserid'] = $this->_user['sessionuserid'];
				$args['questionusername'] = $this->_user['sessionusername'];
				$qknowsid = isset($question['knowsid']) && trim($question['knowsid'])?trim($question['knowsid']):$knowsid;
				if($qknowsid)
				{
					$tmpkid = '0';
					foreach(explode(',',$qknowsid) as $kid)
					{
						$kid = intval($kid);
						if($kid)$tmpkid .= ','.$kid;
					}
					$knows = $this->section->getKnowsListByArgs(array(array("AND","find_in_set(knowsid,:knowsid)",'knowsid',$tmpkid)));
					$args['questionknowsid'] = '';
					foreach($knows as $p)$args['questionknowsid'] .= $p['knowsid'].':'.$p['knows']."\n";
				}
				if($args['question'])
				{
					$this->exam->addQuestions($args);
					$imported++;
				}
			}
			$message = array('statusCode' => 200,"message" => "DeepSeek 已生成并导入 {$imported} 道试题","callbackType" => "forward","forwardUrl" => "index.php?exam-master-questions&page={$page}{$this->u}");
			\PHPEMS\ginkgo::R($message);
		}
		else
		{
			$questypes = $this->basic->getQuestypeList();
			$subjects = $this->basic->getSubjectList();
			$this->tpl->assign('questypes',$questypes);
			$this->tpl->assign('subjects',$subjects);
			$this->tpl->display('question_deepseekimport');
		}
	}

	private function addquestion()
	{
		if($this->ev->get('insertquestion'))
		{
			$type = $this->ev->get('type');
			$questionparent = $this->ev->get('questionparent');
			//批量添加
			if($type)
			{
				$page = $this->ev->get('page');
				$content = $this->ev->get('content');
				$this->exam->insertQuestionBat($content,$questionparent);
			}
			//单个添加
			else
			{
				$args = $this->ev->get('args');
				$targs = $this->ev->get('targs');
				if(!$questionparent)$questionparent = $args['questionparent'];
				$questype = $this->basic->getQuestypeById($args['questiontype']);
				$args['questionuserid'] = $this->_user['sessionuserid'];
				if($questype['questsort'])$choice = 0;
				else $choice = $questype['questchoice'];
				$args['questionanswer'] = $targs['questionanswer'.$choice];
				if(is_array($args['questionanswer']))$args['questionanswer'] = implode('',$args['questionanswer']);
				$page = $this->ev->get('page');
				$args['questioncreatetime'] = TIME;
				$args['questionusername'] = $this->_user['sessionusername'];
				$this->exam->addQuestions($args);
			}
			if($questionparent)
			{
				$this->exam->resetRowsQuestionNumber($questionparent);
				$message = array(
					'statusCode' => 200,
					"message" => "操作成功",
					"callbackType" => "forward",
					"forwardUrl" => "index.php?exam-master-rowsquestions-rowsdetail&questionid={$questionparent}&page={$page}{$u}"
				);
			}
			else
			$message = array(
				'statusCode' => 200,
				"message" => "操作成功",
				"callbackType" => "forward",
			    "forwardUrl" => "index.php?exam-master-questions&page={$page}{$this->u}"
			);
			\PHPEMS\ginkgo::R($message);
		}
		else
		{
			$search = $this->ev->get('search');
			$questypes = $this->basic->getQuestypeList();
			$subjects = $this->basic->getSubjectList();
			$sections = $this->section->getSectionListByArgs(array(array("AND","sectionsubjectid = :sectionsubjectid",'sectionsubjectid',$search['questionsubjectid'])));
			$knows = $this->section->getKnowsListByArgs(array(array("AND","knowsstatus = 1"),array("AND","knowssectionid = :knowssectionid",'knowssectionid',$search['questionsectionid'])));
			$this->tpl->assign('subjects',$subjects);
			$this->tpl->assign('sections',$sections);
			$this->tpl->assign('knows',$knows);
			$this->tpl->assign('questypes',$questypes);
			$this->tpl->display('question_add');
		}
	}

	private function bataddquestion()
	{
		if($this->ev->get('insertquestion'))
		{
			$page = $this->ev->get('page');
			$questionparent = $this->ev->get('questionparent');
			$content = $this->ev->get('content');
			$this->exam->insertQuestionBat($content,$questionparent);
			$message = array(
				'statusCode' => 200,
				"message" => "操作成功",
				"callbackType" => "forward",
			    "forwardUrl" => "index.php?exam-master-questions&page={$page}{$this->u}"
			);
			\PHPEMS\ginkgo::R($message);
		}
		else
		{
			$this->tpl->display('question_batadd');
		}
	}

	private function delquestion()
	{
		$page = $this->ev->get('page');
		$questionid = $this->ev->get('questionid');
		$questionparent = $this->ev->get('questionparent');
		$this->exam->delQuestions($questionid);
		$message = array(
			'statusCode' => 200,
			"message" => "操作成功",
			"callbackType" => "forward",
		    "forwardUrl" => "index.php?exam-master-questions&page={$page}{$this->u}"
		);
		\PHPEMS\ginkgo::R($message);
	}

	private function batdel()
	{
		$page = $this->ev->get('page');
		$delids = $this->ev->get('delids');
		foreach($delids as $questionid => $p)
		$this->exam->delQuestions($questionid);
		$message = array(
			'statusCode' => 200,
			"message" => "操作成功",
			"callbackType" => "forward",
		    "forwardUrl" => "index.php?exam-master-questions&page={$page}{$this->u}"
		);
		\PHPEMS\ginkgo::R($message);
	}

	private function backquestion()
	{
		$page = $this->ev->get('page');
		$questionid = $this->ev->get('questionid');
		$questions = $this->exam->backQuestions($questionid);
		$message = array(
			'statusCode' => 200,
			"message" => "操作成功",
			"callbackType" => "forward",
		    "forwardUrl" => "index.php?exam-master-recyle&page={$page}"
		);
		\PHPEMS\ginkgo::R($message);
	}

	private function modifyquestion()
	{
		if($this->ev->get('modifyquestion'))
		{
			$page = $this->ev->get('page');
			$args = $this->ev->get('args');
			$questionid = $this->ev->get('questionid');
			$targs = $this->ev->get('targs');
			$questype = $this->basic->getQuestypeById($args['questiontype']);
			if($questype['questsort'])$choice = 0;
			else $choice = $questype['questchoice'];
			$args['questionanswer'] = $targs['questionanswer'.$choice];
			if(is_array($args['questionanswer']))$args['questionanswer'] = implode('',$args['questionanswer']);
			$this->exam->modifyQuestions($questionid,$args);
			if($args['questionparent'])
			$message = array(
				'statusCode' => 200,
				"message" => "操作成功",
				"callbackType" => "forward",
				"forwardUrl" => "index.php?exam-master-questions&page={$page}{$this->u}"
			);
			else
			$message = array(
				'statusCode' => 200,
				"message" => "操作成功",
				"callbackType" => "forward",
			    "forwardUrl" => "index.php?exam-master-questions&page={$page}{$this->u}"
			);
			\PHPEMS\ginkgo::R($message);
		}
		else
		{
			$page = $this->ev->get('page');
			$questionparent = $this->ev->get('questionparent');
			$knowsid = $this->ev->get('knowsid');
			$questionid = $this->ev->get('questionid');
			$questypes = $this->basic->getQuestypeList();
			$question = $this->exam->getQuestionByArgs(array(array("AND","questionid = :questionid",'questionid',$questionid)));
			if($question['questionparent'])
			{
				header("location:index.php?exam-master-rowsquestions-modifychildquestion&page={$page}&questionparent={$question['questionparent']}&questionid={$questionid}");
				exit;
			}
			$subjects = $this->basic->getSubjectList();
			foreach($question['questionknowsid'] as $key => $p)
			{
				$knows = $this->section->getKnowsByArgs(array(array("AND","knowsid = :knowsid",'knowsid',$p['knowsid'])));
				$question['questionknowsid'][$key]['knows'] = $knows['knows'];
			}
			$this->tpl->assign('subjects',$subjects);
			$this->tpl->assign('questionparent',$questionparent);
			$this->tpl->assign('questypes',$questypes);
			$this->tpl->assign('page',$page);
			$this->tpl->assign('knowsid',$knowsid);
			$this->tpl->assign('question',$question);
			if($questionparent)
			$this->tpl->display('questionchildrows_modify');
			else
			$this->tpl->display('questions_modify');
		}
	}

	private function ajax()
	{
		switch($this->ev->url(4))
		{
			//根据章节获取知识点信息
			case 'getknowsbysectionid':
			$sectionid = $this->ev->get('sectionid');
			$aknows = $this->section->getKnowsListByArgs(array(array("AND","knowssectionid = :knowssectionid",'knowssectionid',$sectionid),array("AND","knowsstatus = 1")));
			$data = array(array("",'选择知识点'));
			foreach($aknows as $knows)
			{
				$data[] = array($knows['knowsid'],$knows['knows']);
			}
			foreach($data as $p)
			{
				echo "<option value=\"{$p[0]}\">{$p[1]}</option>";
			}
			//exit(json_encode($data));
			break;

			//根据科目获取章节信息
			case 'getsectionsbysubjectid':
			$esid = $this->ev->get('subjectid');
			$aknows = $this->section->getSectionListByArgs(array(array("AND","sectionsubjectid = :sectionsubjectid",'sectionsubjectid',$esid)));
			$data = array(array(0,'选择章节'));
			foreach($aknows as $knows)
			{
				$data[] = array($knows['sectionid'],$knows['section']);
			}
			foreach($data as $p)
			{
				echo "<option value=\"{$p[0]}\">{$p[1]}</option>";
			}
			//exit(json_encode($data));
			break;

			default:
		}
	}

	private function detail()
	{
		$questionid = $this->ev->get('questionid');
		$questionparent = $this->ev->get('questionparent');
		if($questionparent)
		{
			$questions = $this->exam->getQuestionByArgs(array(array("AND","questionparent = :questionparent",'questionparent',$questionparent)));
		}
		else
		{
			$question = $this->exam->getQuestionByArgs(array(array("AND","questionid = :questionid",'questionid',$questionid)));
			$sections = array();
			foreach($question['questionknowsid'] as $key => $p)
			{
				$knows = $this->section->getKnowsByArgs(array(array("AND","knowsid = :knowsid",'knowsid',$p['knowsid'])));
				$question['questionknowsid'][$key]['knows'] = $knows['knows'];
				$sections[] = $this->section->getSectionByArgs(array(array("AND","sectionid = :sectionid",'sectionid',$knows['knowssectionid'])));
			}
			$subject = $this->basic->getSubjectById($sections[0]['sectionsubjectid']);
		}
		$this->tpl->assign("subject",$subject);
		$this->tpl->assign("sections",$sections);
		$this->tpl->assign("question",$question);
		$this->tpl->assign("questions",$questions);
		$this->tpl->display('question_detail');
	}

	private function index()
	{
		$search = $this->ev->get('search');
		$page = $this->ev->get('page');
		$page = $page > 0?$page:1;
		$args = array(array("AND","quest2knows.qkquestionid = questions.questionid"),array("AND","questions.questionstatus = '1'"),array("AND","questions.questionparent = 0"),array("AND","quest2knows.qktype = 0") );
		if($search['questionid'])
		{
			$args[] = array("AND","questions.questionid = :questionid",'questionid',$search['questionid']);
		}
		if($search['keyword'])
		{
			$args[] = array("AND","questions.question LIKE :question",'question','%'.$search['keyword'].'%');
		}
		if($search['username'])
		{
			$args[] = array("AND","questions.questionusername = :questionusername",'questionusername',$search['username']);
		}
		if($search['knowsids'])
		{
			$args[] = array("AND","find_in_set(questions.questionknowsid,:questionknowsid)",'questionknowsid',$search['knowsids']);
		}
		if($search['stime'])
		{
			$args[] = array("AND","questions.questioncreatetime >= :questioncreatetime",'questioncreatetime',strtotime($search['stime']));
		}
		if($search['etime'])
		{
			$args[] = array("AND","questions.questioncreatetime <= :questionendtime",'questionendtime',strtotime($search['etime']));
		}
		if($search['questiontype'])
		{
			$args[] = array("AND","questions.questiontype = :questiontype",'questiontype',$search['questiontype']);
		}
		if($search['questionlevel'])
		{
			$args[] = array("AND","questions.questionlevel = :questionlevel",'questionlevel',$search['questionlevel']);
		}
		if($search['questionknowsid'])
		{
			$args[] = array("AND","quest2knows.qkknowsid = :qkknowsid",'qkknowsid',$search['questionknowsid']);
		}
		else
		{
			$tmpknows = '0';
			if($search['questionsectionid'])
			{
				$knows = $this->section->getKnowsListByArgs(array(array("AND","knowsstatus = 1"),array("AND","knowssectionid = :knowssectionid",'knowssectionid',$search['questionsectionid'])));
				foreach($knows as $p)
				{
					if($p['knowsid'])$tmpknows .= ','.$p['knowsid'];
				}
				$args[] = array("AND","find_in_set(quest2knows.qkknowsid,:qkknowsid)",'qkknowsid',$tmpknows);
			}
			elseif($search['questionsubjectid'])
			{
				$knows = $this->section->getAllKnowsBySubject($search['questionsubjectid']);
				foreach($knows as $p)
				{
					if($p['knowsid'])$tmpknows .= ','.$p['knowsid'];
				}
				$args[] = array("AND","find_in_set(quest2knows.qkknowsid,:qkknowsid)",'qkknowsid',$tmpknows);
			}
		}
		$questypes = $this->basic->getQuestypeList();
		if($search)
		$questions = $this->exam->getQuestionsList($page,50,$args);
		else
		$questions = $this->exam->getSimpleQuestionsList($page,50,array(array("AND","questionstatus = '1'"),array("AND","questionparent = 0")));
		$subjects = $this->basic->getSubjectList();
		$sections = $this->section->getSectionListByArgs(array(array("AND","sectionsubjectid = :sectionsubjectid",'sectionsubjectid',$search['questionsubjectid'])));
		$knows = $this->section->getKnowsListByArgs(array(array("AND","knowsstatus = 1"),array("AND","knowssectionid = :knowssectionid",'knowssectionid',$search['questionsectionid'])));
		$this->tpl->assign('subjects',$subjects);
		$this->tpl->assign('sections',$sections);
		$this->tpl->assign('knows',$knows);
		$this->tpl->assign('questypes',$questypes);
		$this->tpl->assign('questions',$questions);
		$this->tpl->display('questions');
	}
}


?>
