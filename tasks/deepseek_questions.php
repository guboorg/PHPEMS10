<?php
/**
 * DeepSeek 无前台出题并导入 PHPEMS 题库的命令行测试脚本。
 *
 * 使用前只需修改下方“可配置变量”，然后执行：
 *   php tasks/deepseek_questions.php
 * 脚本会校验实际数据库的题型、知识点和表结构，去重后同时写入
 * x2_questions 与 x2_quest2knows，因此在组卷的题库选题窗口中可查到。
 */

namespace PHPEMS;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Access denied: CLI only.\n");
}

// ============================ 可配置变量 ============================
$DEEPSEEK_API_KEY = ''; // 必填，例如 sk-xxxx（不要提交真实密钥）
$DEEPSEEK_API_URL = 'https://api.deepseek.com/chat/completions';
$DEEPSEEK_MODEL = 'deepseek-chat';
$API_TIMEOUT = 120;
$TEMPERATURE = 0.7;

$TOPIC = 'PHP 基础语法与 Web 安全';
$LANGUAGE = '简体中文';
$KNOWS_ID = 1;             // 必须是 x2_knows 中已启用的知识点
$AUTHOR_USER_ID = 1;
$AUTHOR_USERNAME = 'deepseek';
$SKIP_DUPLICATES = true;   // 根据去标签后的题干与现有题库比较
$MOCK_RESPONSE_FILE = '';  // 本地测试可填 DeepSeek content JSON 文件，填写后不请求 API

// type 是 pe10.sql/x2_questype 的 ID；level: 1 易、2 中、3 难；options 是选项数。
$QUESTION_PLAN = array(
    array('type' => 1, 'type_name' => '单选题', 'level' => 1, 'options' => 4, 'count' => 2),
    array('type' => 2, 'type_name' => '多选题', 'level' => 2, 'options' => 4, 'count' => 2),
    array('type' => 3, 'type_name' => '判断题', 'level' => 2, 'options' => 2, 'count' => 1),
    array('type' => 6, 'type_name' => '问答题', 'level' => 3, 'options' => 0, 'count' => 1),
);
// ====================================================================

$root = dirname(__DIR__);
$configFile = $root . '/lib/config.inc.php';
if (!is_file($configFile)) {
    fail('缺少 lib/config.inc.php，请先完成 PHPEMS 数据库配置');
}
require $configFile;

try {
    $pdo = new \PDO('mysql:host=' . DH . ';dbname=' . DB . ';charset=utf8mb4', DU, DP, array(
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ));

    $tables = validateDatabase($pdo, DTH, $QUESTION_PLAN, $KNOWS_ID);
    echo "数据库校验通过，知识点：{$tables['knows']} (#{$KNOWS_ID})\n";

    $content = $MOCK_RESPONSE_FILE
        ? readMockResponse($MOCK_RESPONSE_FILE)
        : requestDeepSeek($DEEPSEEK_API_URL, $DEEPSEEK_API_KEY, $DEEPSEEK_MODEL, $API_TIMEOUT, $TEMPERATURE,
            buildPrompt($TOPIC, $LANGUAGE, $QUESTION_PLAN));
    $questions = decodeQuestions($content);
    validateQuestions($questions, $QUESTION_PLAN);

    $existing = loadExistingQuestions($pdo, DTH);
    $inserted = array();
    $skipped = 0;
    $pdo->beginTransaction();
    foreach ($questions as $question) {
        $key = normalizeQuestion($question['question']);
        if ($SKIP_DUPLICATES && isset($existing[$key])) {
            echo "跳过重复题 #{$existing[$key]}：" . plainText($question['question']) . "\n";
            $skipped++;
            continue;
        }
        $id = insertQuestion($pdo, DTH, $question, $KNOWS_ID, $tables['knows'], $AUTHOR_USER_ID, $AUTHOR_USERNAME);
        $inserted[] = $id;
        $existing[$key] = $id;
        echo "已导入 #{$id}：" . plainText($question['question']) . "\n";
    }
    verifyImportedQuestions($pdo, DTH, $inserted, $KNOWS_ID);
    $pdo->commit();
    echo "完成：导入 " . count($inserted) . " 题，跳过 {$skipped} 题。";
    if ($inserted) echo "题目 ID：" . implode(',', $inserted) . "。";
    echo "\n组卷时选择知识点 #{$KNOWS_ID}，即可在题库中看到上述题目。\n";
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail($e->getMessage());
}

function validateDatabase(\PDO $pdo, $prefix, array $plan, $knowsId)
{
    foreach (array('questions', 'quest2knows', 'questype', 'knows') as $table) {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($prefix . $table));
        if (!$stmt->fetchColumn()) throw new \RuntimeException("数据库缺少表 {$prefix}{$table}");
    }
    $required = array('questiontype', 'question', 'questionselect', 'questionanswer', 'questiondescribe',
        'questionknowsid', 'questionlevel', 'questionstatus');
    $columns = $pdo->query('SHOW COLUMNS FROM `' . $prefix . 'questions`')->fetchAll(\PDO::FETCH_COLUMN);
    $missing = array_diff($required, $columns);
    if ($missing) throw new \RuntimeException('questions 表缺少字段：' . implode(',', $missing));

    $typeStmt = $pdo->prepare("SELECT questid, questype FROM `{$prefix}questype` WHERE questid = ?");
    foreach ($plan as $item) {
        $typeStmt->execute(array((int)$item['type']));
        $type = $typeStmt->fetch();
        if (!$type) throw new \RuntimeException("题型 #{$item['type']} 不存在");
        if ($type['questype'] !== $item['type_name']) {
            throw new \RuntimeException("题型 #{$item['type']} 数据库名称为“{$type['questype']}”，与配置“{$item['type_name']}”不符");
        }
    }
    $stmt = $pdo->prepare("SELECT knows FROM `{$prefix}knows` WHERE knowsid = ? AND knowsstatus = 1");
    $stmt->execute(array((int)$knowsId));
    $knows = $stmt->fetchColumn();
    if ($knows === false) throw new \RuntimeException("知识点 #{$knowsId} 不存在或未启用");
    return array('knows' => $knows);
}

function buildPrompt($topic, $language, array $plan)
{
    return "你是严谨的考试出题专家。请围绕“{$topic}”用{$language}出题。\n"
        . "出题计划：" . json_encode($plan, JSON_UNESCAPED_UNICODE) . "\n"
        . '只返回 {"questions":[...]} JSON 对象，不要 Markdown。questions 每项必须为 '
        . '{"type":题型ID,"level":1到3,"question":"题干","options":["A选项文字",...],'
        . '"answer":"答案","analysis":"详细解析"}。选项内不带 A/B 前缀；单选答案如 A，多选如 ACD，'
        . '判断题 options 必须是["正确","错误"]且答案用 A 或 B，问答题 options 是空数组且 answer 为参考答案。'
        . '严格满足每组的数量、题型、难度和选项数，每题必须有非空解析。';
}

function requestDeepSeek($url, $apiKey, $model, $timeout, $temperature, $prompt)
{
    if ($apiKey === '') throw new \RuntimeException('请先在文件顶部填写 $DEEPSEEK_API_KEY');
    if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL 扩展未安装');
    $payload = json_encode(array('model' => $model, 'temperature' => $temperature,
        'response_format' => array('type' => 'json_object'),
        'messages' => array(array('role' => 'system', 'content' => '你只输出可解析的 JSON。'),
            array('role' => 'user', 'content' => $prompt))), JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)$timeout, CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $apiKey),
        CURLOPT_POSTFIELDS => $payload));
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new \RuntimeException('DeepSeek 请求失败：' . $error);
    $response = json_decode($body, true);
    if ($status < 200 || $status >= 300) throw new \RuntimeException("DeepSeek HTTP {$status}：" . ($response['error']['message'] ?? $body));
    if (!isset($response['choices'][0]['message']['content'])) throw new \RuntimeException('DeepSeek 响应中没有题目内容');
    return $response['choices'][0]['message']['content'];
}

function readMockResponse($file)
{
    if (!is_file($file)) throw new \RuntimeException("模拟响应文件不存在：{$file}");
    return file_get_contents($file);
}

function decodeQuestions($content)
{
    $content = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($content)));
    $decoded = json_decode($content, true);
    if (isset($decoded['questions'])) $decoded = $decoded['questions'];
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('DeepSeek 返回的不是有效题目 JSON：' . json_last_error_msg());
    }
    return $decoded;
}

function validateQuestions(array $questions, array $plan)
{
    $expected = array();
    foreach ($plan as $item) $expected[$item['type'] . ':' . $item['level']] = $item;
    $actual = array();
    foreach ($questions as $index => $q) {
        foreach (array('type', 'level', 'question', 'options', 'answer', 'analysis') as $field) {
            if (!array_key_exists($field, $q)) throw new \RuntimeException("第 " . ($index + 1) . " 题缺少 {$field}");
        }
        $key = (int)$q['type'] . ':' . (int)$q['level'];
        if (!isset($expected[$key])) throw new \RuntimeException("第 " . ($index + 1) . " 题的题型/难度 {$key} 不在计划中");
        if (!is_array($q['options']) || count($q['options']) !== (int)$expected[$key]['options'])
            throw new \RuntimeException("第 " . ($index + 1) . " 题选项数不符");
        if (trim($q['question']) === '' || trim($q['answer']) === '' || trim($q['analysis']) === '')
            throw new \RuntimeException("第 " . ($index + 1) . " 题的题干、答案或解析为空");
        if (in_array((int)$q['type'], array(1, 2, 3), true)) {
            $answer = strtoupper(preg_replace('/[^A-Z]/i', '', $q['answer']));
            $max = chr(64 + count($q['options']));
            if ($answer === '' || preg_match('/[^A-' . $max . ']/', $answer)) throw new \RuntimeException("第 " . ($index + 1) . " 题答案超出选项范围");
        }
        $actual[$key] = isset($actual[$key]) ? $actual[$key] + 1 : 1;
    }
    foreach ($expected as $key => $item) {
        if (($actual[$key] ?? 0) !== (int)$item['count']) throw new \RuntimeException("题型/难度 {$key} 应有 {$item['count']} 题，实际 " . ($actual[$key] ?? 0) . ' 题');
    }
}

function loadExistingQuestions(\PDO $pdo, $prefix)
{
    $result = array();
    foreach ($pdo->query("SELECT questionid, question FROM `{$prefix}questions` WHERE questionstatus = 1") as $row)
        $result[normalizeQuestion($row['question'])] = $row['questionid'];
    return $result;
}

function insertQuestion(\PDO $pdo, $prefix, array $q, $knowsId, $knows, $userId, $username)
{
    $optionsHtml = '';
    foreach ($q['options'] as $i => $option) $optionsHtml .= '<p>' . chr(65 + $i) . ':' . plainText($option) . '</p>';
    $answer = in_array((int)$q['type'], array(1, 2, 3), true)
        ? strtoupper(preg_replace('/[^A-Z]/i', '', $q['answer'])) : $q['answer'];
    $knowsData = serialize(array(array('knowsid' => (string)$knowsId, 'knows' => $knows)));
    $sql = "INSERT INTO `{$prefix}questions` (questiontype,question,questionuserid,questionusername,questionlastmodifyuser,"
        . 'questionselect,questionselectnumber,questionanswer,questiondescribe,questionknowsid,questioncreatetime,'
        . 'questionstatus,questionhtml,questionparent,questionsequence,questionlevel,questiondeler,questiondeltime) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
    $pdo->prepare($sql)->execute(array((int)$q['type'], html($q['question']), (int)$userId, $username, '', html($optionsHtml),
        count($q['options']), html($answer), html($q['analysis']), $knowsData, time(), 1, '', 0, 0, (int)$q['level'], '', 0));
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}quest2knows` (qkquestionid,qkknowsid,qktype) VALUES (?,?,0)")
        ->execute(array($id, (int)$knowsId));
    return $id;
}

function verifyImportedQuestions(\PDO $pdo, $prefix, array $ids, $knowsId)
{
    if (!$ids) return;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, array((int)$knowsId));
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT q.questionid) FROM `{$prefix}questions` q "
        . "JOIN `{$prefix}quest2knows` k ON k.qkquestionid=q.questionid AND k.qktype=0 "
        . "WHERE q.questionstatus=1 AND q.questionid IN ({$marks}) AND k.qkknowsid=?");
    $stmt->execute($params);
    if ((int)$stmt->fetchColumn() !== count($ids)) throw new \RuntimeException('导入后可见性校验失败，事务已回滚');
}

function html($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function plainText($value) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'))); }
function normalizeQuestion($value) { return mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', plainText($value)), 'UTF-8'); }
function fail($message) { fwrite(STDERR, "错误：{$message}\n"); exit(1); }
