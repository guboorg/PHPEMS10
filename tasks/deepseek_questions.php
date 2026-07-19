<?php
namespace PHPEMS;

/*
 * DeepSeek 命令行出题、入库测试脚本（无前台页面）。
 *
 * 使用方法：
 * 1. 修改下面“可配置变量”；
 * 2. 先将 $IMPORT_TO_DATABASE 设为 false 检查输出；
 * 3. 执行：php tasks/deepseek_questions.php
 */

// ------------------------------ 可配置变量 ------------------------------
$DEEPSEEK_API_KEY = '请替换为 DeepSeek API Key';
$DEEPSEEK_API_URL = 'https://api.deepseek.com/chat/completions';
$DEEPSEEK_MODEL = 'deepseek-chat';
$QUESTION_TOPIC = 'PHP 基础语法，包括变量、数组、函数和面向对象';
$QUESTION_COUNT = 5;
$QUESTION_TYPE = 1;                   // 1单选、2多选、3判断、4定值填空、5填空、6问答
$QUESTION_LEVEL = 2;                  // 难度 1（容易）至 5（困难）
$OPTION_LABELS = array('A','B','C','D'); // 选择题选项；非选择题设置为空数组
$ANSWER_REQUIREMENT = '答案必须准确；选择题答案只写选项字母，多选题如 AC';
$ANALYSIS_REQUIREMENT = '解析需说明正确答案的理由，并简要说明干扰项错误原因';
$KNOWS_IDS = '';                      // PHPEMS 知识点 ID，多个用英文逗号分隔
$IMPORT_TO_DATABASE = true;           // false 只打印生成结果，不写数据库
$IMPORT_USER_ID = 1;
$IMPORT_USERNAME = 'DeepSeek CLI';
$TEMPERATURE = 0.7;
$MAX_TOKENS = 4096;
$HTTP_TIMEOUT = 120;
// ------------------------------------------------------------------------

if(php_sapi_name() != 'cli')exit("Access denied: CLI only.\n");
set_time_limit(0);
define('PEPATH',dirname(dirname(__FILE__)));

class deepseek_question_task
{
	private $config;
	private $ev;
	private $exam;
	private $section;

	public function __construct($config)
	{
		$this->config = $config;
	}

	private function fail($message)
	{
		fwrite(STDERR,"[失败] ".$message."\n");
		exit(1);
	}

	private function requestQuestions()
	{
		if(!function_exists('curl_init'))$this->fail('PHP curl 扩展未启用');
		if(!$this->config['api_key'] || strpos($this->config['api_key'],'请替换') !== false)
		{
			$this->fail('请先在文件顶部设置 $DEEPSEEK_API_KEY');
		}
		if($this->config['count'] < 1)$this->fail('$QUESTION_COUNT 必须大于 0');
		if($this->config['type'] < 1 || $this->config['type'] > 6)$this->fail('$QUESTION_TYPE 必须是 1 至 6');
		if($this->config['level'] < 1 || $this->config['level'] > 5)$this->fail('$QUESTION_LEVEL 必须是 1 至 5');

		$labels = $this->config['option_labels'] ? implode('、',$this->config['option_labels']) : '无选项';
		$systemPrompt = '你是 PHPEMS 试题生成助手。只输出一个合法 JSON 对象，不要 Markdown、代码围栏或额外说明。'
			.'格式必须为：{"questions":[{"type":1,"question":"题干","options":["A. 选项"],"select_number":4,"answer":"A","analysis":"解析","level":2,"knowsid":""}]}。'
			.'type 只能为 1 至 6，level 只能为 1 至 5；options 没有选项时必须是空数组。';
		$userPrompt = "生成主题：{$this->config['topic']}\n"
			."题目数量：{$this->config['count']}\n题型 ID：{$this->config['type']}\n"
			."难度：{$this->config['level']}\n选项标识：{$labels}\n"
			."答案要求：{$this->config['answer_requirement']}\n"
			."解析要求：{$this->config['analysis_requirement']}\n"
			."知识点 ID：{$this->config['knows_ids']}\n每道题必须包含题干、题型、难度、答案和解析。";
		$payload = array(
			'model' => $this->config['model'],
			'messages' => array(
				array('role'=>'system','content'=>$systemPrompt),
				array('role'=>'user','content'=>$userPrompt)
			),
			'response_format' => array('type'=>'json_object'),
			'temperature' => $this->config['temperature'],
			'max_tokens' => $this->config['max_tokens']
		);

		$ch = curl_init($this->config['api_url']);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		curl_setopt($ch,CURLOPT_POST,true);
		curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-Type: application/json','Authorization: Bearer '.$this->config['api_key']));
		curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE));
		curl_setopt($ch,CURLOPT_TIMEOUT,$this->config['timeout']);
		$response = curl_exec($ch);
		$error = curl_error($ch);
		$httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE);
		curl_close($ch);
		if($response === false || $httpCode < 200 || $httpCode >= 300)
		{
			$this->fail('DeepSeek 请求错误（HTTP '.$httpCode.'）：'.($error?$error:$response));
		}
		$result = json_decode($response,true);
		if(!is_array($result))$this->fail('DeepSeek API 返回的外层数据不是合法 JSON：'.$response);
		if(isset($result['error']['message']))$this->fail('DeepSeek API：'.$result['error']['message']);
		$content = isset($result['choices'][0]['message']['content']) ? trim($result['choices'][0]['message']['content']) : '';
		$data = json_decode($content,true);
		if(!is_array($data) || !isset($data['questions']) || !is_array($data['questions']))
		{
			$this->fail('模型没有返回约定的 questions JSON：'.$content);
		}
		return $data['questions'];
	}

	private function makeKnows($knowsIds)
	{
		if(!$knowsIds)return '';
		$ids = array();
		foreach(explode(',',$knowsIds) as $id)if(intval($id))$ids[] = intval($id);
		if(!$ids)return '';
		$knows = $this->section->getKnowsListByArgs(array(array('AND','find_in_set(knowsid,:knowsid)','knowsid',implode(',',$ids))));
		$value = '';
		foreach($knows as $item)$value .= $item['knowsid'].':'.$item['knows']."\n";
		return $value;
	}

	private function importQuestions($questions)
	{
		$this->ev = ginkgo::make('ev');
		$this->exam = ginkgo::make('exam','exam');
		$this->section = ginkgo::make('section','exam');
		$imported = 0;
		foreach($questions as $index => $question)
		{
			$title = trim(isset($question['question'])?$question['question']:'');
			$answer = trim(isset($question['answer'])?$question['answer']:'');
			$analysis = trim(isset($question['analysis'])?$question['analysis']:'');
			if(!$title || !$answer || !$analysis)
			{
				fwrite(STDERR,'[跳过] 第 '.($index+1)." 题缺少题干、答案或解析\n");
				continue;
			}
			$options = isset($question['options']) && is_array($question['options']) ? $question['options'] : array();
			$type = isset($question['type'])?intval($question['type']):$this->config['type'];
			$level = isset($question['level'])?intval($question['level']):$this->config['level'];
			if($type < 1 || $type > 6)$type = $this->config['type'];
			if($level < 1 || $level > 5)$level = $this->config['level'];
			$knowsIds = isset($question['knowsid']) && trim($question['knowsid']) ? trim($question['knowsid']) : $this->config['knows_ids'];
			$args = array(
				'questiontype'=>$type,
				'question'=>$this->ev->addSlashes(htmlspecialchars($title,ENT_QUOTES,'UTF-8')),
				'questionselect'=>$this->ev->addSlashes(htmlspecialchars(implode("\n",$options),ENT_QUOTES,'UTF-8')),
				'questionselectnumber'=>count($options),
				'questionanswer'=>$this->ev->addSlashes(htmlspecialchars($answer,ENT_QUOTES,'UTF-8')),
				'questiondescribe'=>$this->ev->addSlashes(htmlspecialchars($analysis,ENT_QUOTES,'UTF-8')),
				'questionknowsid'=>$this->makeKnows($knowsIds),
				'questionlevel'=>$level,
				'questioncreatetime'=>TIME,
				'questionuserid'=>$this->config['user_id'],
				'questionusername'=>$this->config['username']
			);
			$id = $this->exam->addQuestions($args);
			echo '[入库] questionid='.$id.' '.$title."\n";
			$imported++;
		}
		return $imported;
	}

	public function run()
	{
		$questions = $this->requestQuestions();
		echo json_encode(array('questions'=>$questions),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
		if(!$this->config['import'])
		{
			echo "[完成] 预览模式，未写入数据库。\n";
			return;
		}
		$count = $this->importQuestions($questions);
		echo '[完成] DeepSeek 返回 '.count($questions).' 道，成功导入 '.$count." 道。\n";
	}
}

$config = array(
	'api_key'=>$DEEPSEEK_API_KEY,'api_url'=>$DEEPSEEK_API_URL,'model'=>$DEEPSEEK_MODEL,
	'topic'=>$QUESTION_TOPIC,'count'=>intval($QUESTION_COUNT),'type'=>intval($QUESTION_TYPE),
	'level'=>intval($QUESTION_LEVEL),'option_labels'=>$OPTION_LABELS,
	'answer_requirement'=>$ANSWER_REQUIREMENT,'analysis_requirement'=>$ANALYSIS_REQUIREMENT,
	'knows_ids'=>$KNOWS_IDS,'import'=>$IMPORT_TO_DATABASE,'user_id'=>intval($IMPORT_USER_ID),
	'username'=>$IMPORT_USERNAME,'temperature'=>$TEMPERATURE,'max_tokens'=>intval($MAX_TOKENS),
	'timeout'=>intval($HTTP_TIMEOUT)
);

include PEPATH.'/lib/init.cls.php';
ginkgo::loadMoudle();
$task = new deepseek_question_task($config);
$task->run();
