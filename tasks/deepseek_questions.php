<?php
/**
 * DeepSeek 无前台出题并导入 PHPEMS 题库的命令行测试脚本。
 *
 * 使用前只需修改下方“可配置变量”，然后执行：
 *   php tasks/deepseek_questions.php
 * 开启导入时，脚本会校验实际数据库的题型、知识点和表结构，去重后同时写入
 * x2_questions 与 x2_quest2knows，因此在组卷的题库选题窗口中可查到。
 */

namespace PHPEMS;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Access denied: CLI only.\n");
}

// ============================ 可配置变量 ============================
$DEEPSEEK_API_KEY = '';               // DeepSeek API Key，请勿提交真实密钥
$DEEPSEEK_API_URL = 'https://api.deepseek.com/chat/completions';
$DEEPSEEK_MODEL = 'deepseek-chat';
$QUESTION_TOPIC = '从中国大陆小学单词中抽取词汇，给出新版DJ音标，题目形式为"根据音标写出单词"';
$QUESTION_COUNT = 1;
$QUESTION_TYPE = 5;                   // 1单选、2多选、3判断、4定值填空、5填空、6问答；手工组卷默认启用填空题(5)
$QUESTION_LEVEL = 2;                  // 难度 1（容易）至 5（困难）
$OPTION_LABELS = array();             // 选择题选项标签，如 array('A','B','C','D')；非选择题为空
$ANSWER_REQUIREMENT = '根据给出的音标写出对应单词';
$ANALYSIS_REQUIREMENT = '根据单词读音给出每个字母或者字母组合对应的音标，以及该单词发音所需注意的事项';
$KNOWS_IDS = '1';                     // PHPEMS 知识点 ID，多个用英文逗号分隔
$IMPORT_TO_DATABASE = true;           // false 只打印生成结果，不连接或写入数据库
$IMPORT_USER_ID = 3;
$IMPORT_USERNAME = 'Tianyi';
$TEMPERATURE = 0.7;
$MAX_TOKENS = 4096;
$HTTP_TIMEOUT = 120;

// 辅助测试变量。
$SKIP_DUPLICATES = true;
$MOCK_RESPONSE_FILE = '';             // 填写后从本地读取 DeepSeek content JSON，不请求 API
// ====================================================================

try {
    $knowsIds = parseKnowsIds($KNOWS_IDS);
    validateSettings($QUESTION_COUNT, $QUESTION_TYPE, $QUESTION_LEVEL, $OPTION_LABELS);
    $prompt = buildPrompt($QUESTION_TOPIC, $QUESTION_COUNT, $QUESTION_TYPE, $QUESTION_LEVEL,
        $OPTION_LABELS, $ANSWER_REQUIREMENT, $ANALYSIS_REQUIREMENT);
    $content = $MOCK_RESPONSE_FILE ? readMockResponse($MOCK_RESPONSE_FILE)
        : requestDeepSeek($DEEPSEEK_API_URL, $DEEPSEEK_API_KEY, $DEEPSEEK_MODEL, $HTTP_TIMEOUT,
            $TEMPERATURE, $MAX_TOKENS, $prompt);
    $questions = decodeQuestions($content);
    validateQuestions($questions, $QUESTION_COUNT, $QUESTION_TYPE, $QUESTION_LEVEL, $OPTION_LABELS);

    echo json_encode(array('questions' => $questions), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if (!$IMPORT_TO_DATABASE) {
        echo "IMPORT_TO_DATABASE=false，仅输出生成结果，未连接数据库。\n";
        exit(0);
    }

    $configFile = dirname(__DIR__) . '/lib/config.inc.php';
    if (!is_file($configFile)) throw new \RuntimeException('缺少 lib/config.inc.php，请先完成 PHPEMS 数据库配置');
    require $configFile;
    $pdo = new \PDO('mysql:host=' . DH . ';dbname=' . DB . ';charset=utf8mb4', DU, DP, array(
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ));

    $database = validateDatabase($pdo, DTH, $QUESTION_TYPE, $knowsIds);
    echo "数据库校验通过，题型：{$database['type_name']}；知识点："
        . implode('、', array_values($database['knows'])) . "\n";

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
        $id = insertQuestion($pdo, DTH, $question, $knowsIds, $database['knows'], $OPTION_LABELS,
            $IMPORT_USER_ID, $IMPORT_USERNAME);
        $inserted[] = $id;
        $existing[$key] = $id;
        echo "已导入 #{$id}：" . plainText($question['question']) . "\n";
    }
    verifyImportedQuestions($pdo, DTH, $inserted, $knowsIds, $QUESTION_TYPE, $QUESTION_LEVEL);
    $pdo->commit();
    echo "完成：导入 " . count($inserted) . " 题，跳过 {$skipped} 题。";
    if ($inserted) echo "题目 ID：" . implode(',', $inserted) . "。";
    echo "\n组卷时选择知识点 #" . implode(',#', $knowsIds) . "，即可看到上述题目。\n";
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fail($e->getMessage());
}

function parseKnowsIds($value)
{
    $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), 'strlen')));
    if (!$ids) throw new \RuntimeException('$KNOWS_IDS 不能为空');
    foreach ($ids as $id) if (!ctype_digit($id) || (int)$id < 1) throw new \RuntimeException("无效知识点 ID：{$id}");
    return array_map('intval', $ids);
}

function validateSettings($count, $type, $level, array $labels)
{
    if ((int)$count < 1) throw new \RuntimeException('$QUESTION_COUNT 必须大于 0');
    if ((int)$type < 1 || (int)$type > 6) throw new \RuntimeException('$QUESTION_TYPE 必须为 1 至 6');
    if ((int)$level < 1 || (int)$level > 5) throw new \RuntimeException('$QUESTION_LEVEL 必须为 1 至 5');
    if (in_array((int)$type, array(1, 2), true) && count($labels) < 2)
        throw new \RuntimeException('单选题或多选题至少需要两个 $OPTION_LABELS');
    if (!in_array((int)$type, array(1, 2), true) && $labels)
        throw new \RuntimeException('非选择题的 $OPTION_LABELS 必须为空数组');
}

function validateDatabase(\PDO $pdo, $prefix, $questionType, array $knowsIds)
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

    $typeStmt = $pdo->prepare("SELECT questype FROM `{$prefix}questype` WHERE questid = ?");
    $typeStmt->execute(array((int)$questionType));
    $typeName = $typeStmt->fetchColumn();
    if ($typeName === false) throw new \RuntimeException("数据库中不存在题型 #{$questionType}");
    $stmt = $pdo->prepare("SELECT knows FROM `{$prefix}knows` WHERE knowsid = ? AND knowsstatus = 1");
    $knows = array();
    foreach ($knowsIds as $id) {
        $stmt->execute(array($id));
        $name = $stmt->fetchColumn();
        if ($name === false) throw new \RuntimeException("知识点 #{$id} 不存在或未启用");
        $knows[$id] = $name;
    }
    return array('type_name' => $typeName, 'knows' => $knows);
}

function buildPrompt($topic, $count, $type, $level, array $labels, $answerRequirement, $analysisRequirement)
{
    return "你是严谨的考试出题专家。主题：{$topic}\n"
        . "生成 {$count} 题；题型 ID={$type}；难度={$level}（不得改变）；选项标签："
        . json_encode($labels, JSON_UNESCAPED_UNICODE) . "。\n答案要求：{$answerRequirement}\n解析要求：{$analysisRequirement}\n"
        . '只返回 {"questions":[...]} JSON 对象，不要 Markdown。questions 每项必须为 '
        . '{"type":题型ID,"level":难度,"question":"题干","options":["选项文字",...],'
        . '"answer":"答案","analysis":"详细解析"}。非选择题 options 必须为 []；选择题选项数必须与标签数相同。';
}

function requestDeepSeek($url, $apiKey, $model, $timeout, $temperature, $maxTokens, $prompt)
{
    if ($apiKey === '') throw new \RuntimeException('请先在文件顶部填写 $DEEPSEEK_API_KEY');
    if (!function_exists('curl_init')) throw new \RuntimeException('PHP cURL 扩展未安装');
    $payload = json_encode(array('model' => $model, 'temperature' => $temperature, 'max_tokens' => (int)$maxTokens,
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

function validateQuestions(array $questions, $count, $type, $level, array $labels)
{
    if (count($questions) !== (int)$count)
        throw new \RuntimeException("应生成 {$count} 题，实际返回 " . count($questions) . ' 题');
    foreach ($questions as $index => $q) {
        foreach (array('type', 'level', 'question', 'options', 'answer', 'analysis') as $field) {
            if (!array_key_exists($field, $q)) throw new \RuntimeException("第 " . ($index + 1) . " 题缺少 {$field}");
        }
        if ((int)$q['type'] !== (int)$type || (int)$q['level'] !== (int)$level)
            throw new \RuntimeException("第 " . ($index + 1) . " 题的题型或难度与配置不符");
        if (!is_array($q['options']) || count($q['options']) !== count($labels))
            throw new \RuntimeException("第 " . ($index + 1) . " 题选项数不符");
        if (trim($q['question']) === '' || trim($q['answer']) === '' || trim($q['analysis']) === '')
            throw new \RuntimeException("第 " . ($index + 1) . " 题的题干、答案或解析为空");
        if (in_array((int)$type, array(1, 2), true)) {
            normalizeSelectionAnswer($q['answer'], $labels, $index + 1);
        }
    }
}

function loadExistingQuestions(\PDO $pdo, $prefix)
{
    $result = array();
    foreach ($pdo->query("SELECT questionid, question FROM `{$prefix}questions` WHERE questionstatus = 1") as $row)
        $result[normalizeQuestion($row['question'])] = $row['questionid'];
    return $result;
}

function insertQuestion(\PDO $pdo, $prefix, array $q, array $knowsIds, array $knows, array $labels, $userId, $username)
{
    $optionsHtml = '';
    foreach ($q['options'] as $i => $option) $optionsHtml .= '<p>' . $labels[$i] . ':' . plainText($option) . '</p>';
    $answer = in_array((int)$q['type'], array(1, 2), true)
        ? normalizeSelectionAnswer($q['answer'], $labels) : $q['answer'];
    $knowsData = array();
    foreach ($knowsIds as $id) $knowsData[] = array('knowsid' => (string)$id, 'knows' => $knows[$id]);
    $knowsData = serialize($knowsData);
    $sql = "INSERT INTO `{$prefix}questions` (questiontype,question,questionuserid,questionusername,questionlastmodifyuser,"
        . 'questionselect,questionselectnumber,questionanswer,questiondescribe,questionknowsid,questioncreatetime,'
        . 'questionstatus,questionhtml,questionparent,questionsequence,questionlevel,questiondeler,questiondeltime) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
    $pdo->prepare($sql)->execute(array((int)$q['type'], html($q['question']), (int)$userId, $username, '', html($optionsHtml),
        count($q['options']), html($answer), html($q['analysis']), $knowsData, time(), 1, '', 0, 0, (int)$q['level'], '', 0));
    $id = (int)$pdo->lastInsertId();
    $relation = $pdo->prepare("INSERT INTO `{$prefix}quest2knows` (qkquestionid,qkknowsid,qktype) VALUES (?,?,0)");
    foreach ($knowsIds as $knowsId) $relation->execute(array($id, $knowsId));
    return $id;
}

function verifyImportedQuestions(\PDO $pdo, $prefix, array $ids, array $knowsIds, $questionType, $questionLevel)
{
    if (!$ids) return;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $knowledgeMarks = implode(',', array_fill(0, count($knowsIds), '?'));
    $params = array_merge($ids, $knowsIds, array((int)$questionType, (int)$questionLevel));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$prefix}questions` q "
        . "JOIN `{$prefix}quest2knows` k ON k.qkquestionid=q.questionid AND k.qktype=0 "
        . "WHERE q.questionstatus=1 AND q.questionparent=0 AND q.questionid IN ({$marks}) "
        . "AND k.qkknowsid IN ({$knowledgeMarks}) AND q.questiontype=? AND q.questionlevel=?");
    $stmt->execute($params);
    if ((int)$stmt->fetchColumn() !== count($ids) * count($knowsIds))
        throw new \RuntimeException('导入后可见性校验失败，事务已回滚');
}

function html($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function normalizeSelectionAnswer($value, array $labels, $questionNumber = 0)
{
    $answer = preg_replace('/[,\s]+/u', '', trim($value));
    $remaining = $answer;
    foreach ($labels as $label) $remaining = str_replace($label, '', $remaining);
    if ($answer === '' || $remaining !== '') {
        $prefix = $questionNumber ? "第 {$questionNumber} 题" : '';
        throw new \RuntimeException($prefix . '答案不在 $OPTION_LABELS 中');
    }
    return $answer;
}
function plainText($value) { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'))); }
function normalizeQuestion($value) { return mb_strtolower(preg_replace('/[\s\p{P}\p{S}]+/u', '', plainText($value)), 'UTF-8'); }
function fail($message) { fwrite(STDERR, "错误：{$message}\n"); exit(1); }
