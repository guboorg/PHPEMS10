# DeepSeek 生成试题导入插件使用说明

本文档说明 PHPEMS 后台「DeepSeek生成导入」插件的配置、使用步骤、提示词写法、导入字段映射和常见问题。插件按 DeepSeek 官方 API 文档接入 Chat Completions 接口，并使用 JSON Output 要求模型返回结构化题目数据。

## 1. 功能简介

插件用于在考试后台调用 DeepSeek 生成试题，并直接导入 PHPEMS 普通试题题库。支持生成和导入以下字段：

- 题型 ID：写入 `questiontype`。
- 题干：写入 `question`。
- 题目选项：写入 `questionselect`，多选项按换行保存。
- 选项数量：写入 `questionselectnumber`。
- 标准答案：写入 `questionanswer`。
- 答案解析：写入 `questiondescribe`。
- 题目难度：写入 `questionlevel`，建议使用 1 到 5。
- 知识点 ID：转换为 PHPEMS 的知识点关联信息后写入 `questionknowsid` 和 `quest2knows`。
- 创建人和创建时间：使用当前后台管理员账号及当前时间写入。

## 2. 使用前准备

### 2.1 获取 DeepSeek API Key

1. 打开 DeepSeek Platform，创建或复制 API Key。
2. 确认账户余额、模型权限和 API 调用限额可用。
3. API Key 只在本次提交表单时使用，插件不会保存到数据库或配置文件。

### 2.2 确认服务器环境

服务器需要满足：

- PHP 已启用 `curl` 扩展，因为插件通过 `curl_init` 请求 DeepSeek API。
- 服务器可以访问 `https://api.deepseek.com/chat/completions`。
- 后台管理员账号拥有考试模块管理权限。

### 2.3 准备 PHPEMS 基础数据

建议先在 PHPEMS 后台完成：

1. 创建科目、章节、知识点。
2. 创建或确认题型，并记录题型 ID。
3. 确认题型的作答规则，例如单选、多选、判断、填空或问答。

> 注意：插件页面中的「默认题型」直接使用 PHPEMS 已有题型列表，DeepSeek 返回的 `type` 字段也应使用 PHPEMS 题型 ID。

## 3. 入口位置

可以通过以下两个入口进入插件页面：

1. 后台左侧菜单：`考试模块 -> 试题管理 -> DeepSeek生成导入`。
2. 普通试题管理页面：点击右上角 `添加试题` 下拉按钮，再选择 `DeepSeek生成导入`。

页面地址为：

```text
index.php?exam-master-questions-deepseekimport
```

## 4. 页面字段说明

| 字段 | 必填 | 说明 | 建议值 |
| --- | --- | --- | --- |
| API Key | 是 | DeepSeek API Key。提交后用于请求 API，不会被插件持久化保存。 | `sk-...` |
| 模型 | 否 | DeepSeek 模型名。 | `deepseek-chat` |
| 生成数量 | 否 | 本次希望生成的试题数量。 | 5 到 20 |
| 最大输出 Token | 否 | 限制模型最多输出多少 token。题目越多、解析越长，需要越大。 | 4096 或更高 |
| 默认题型 | 否 | 当 DeepSeek 返回题目未包含有效 `type` 时使用。 | 按题库题型选择 |
| 默认难度 | 否 | 当 DeepSeek 返回题目未包含有效 `level` 时使用。 | 1 到 5 |
| 默认知识点ID | 否 | 当 DeepSeek 返回题目未包含 `knowsid` 时使用，多个 ID 用英文逗号分隔。 | `12,15` |
| 生成要求 | 是 | 给 DeepSeek 的出题要求。 | 见下方示例 |

## 5. 推荐提示词写法

### 5.1 单选题示例

```text
围绕高中数学「函数单调性」生成 10 道单选题。
要求：
1. 每题 4 个选项，选项用 A-D 标识。
2. 答案只能是 A、B、C、D 中的一个。
3. 解析要写出关键推导步骤。
4. 难度分布：3 道简单、5 道中等、2 道较难。
5. 题型 ID 使用 1，知识点 ID 使用 12。
```

### 5.2 多选题示例

```text
围绕 PHP 数组和字符串处理生成 8 道多选题。
要求：
1. 每题 4 个选项，可能有 2 个或 3 个正确答案。
2. 答案格式使用连续字母，例如 AB、ACD。
3. 解析说明每个正确选项和错误选项的原因。
4. 题型 ID 使用 2，难度 level 使用 2 到 4。
```

### 5.3 判断题示例

```text
围绕计算机网络 TCP/IP 基础生成 10 道判断题。
要求：
1. 选项为 A. 对、B. 错。
2. 答案使用 A 或 B。
3. 每题给出一句简明解析。
4. 题型 ID 使用 3，默认难度为 2。
```

### 5.4 问答题示例

```text
围绕中国近现代史生成 5 道简答题。
要求：
1. options 返回空数组。
2. select_number 返回 0。
3. answer 写参考答案要点。
4. analysis 写评分提示和易错点。
5. 题型 ID 使用 5，难度为 3。
```

## 6. DeepSeek 返回 JSON 格式要求

插件会在系统提示词中要求 DeepSeek 返回如下 JSON 对象：

```json
{
  "questions": [
    {
      "type": 1,
      "question": "题干",
      "options": ["A. 选项", "B. 选项", "C. 选项", "D. 选项"],
      "select_number": 4,
      "answer": "A",
      "analysis": "答案解析",
      "level": 3,
      "knowsid": "12,15"
    }
  ]
}
```

字段说明：

- `questions`：题目数组，必须存在。
- `type`：PHPEMS 题型 ID，必须尽量与后台题型一致。
- `question`：题干，不能为空。
- `options`：选项数组；问答题、填空题等可以传空数组。
- `select_number`：选项数量；没有选项时传 0。
- `answer`：标准答案。
- `analysis`：答案解析。
- `level`：难度，建议 1 到 5。
- `knowsid`：知识点 ID 字符串，多个 ID 使用英文逗号分隔。

## 7. 导入后的检查流程

生成并导入成功后，建议按以下步骤检查：

1. 进入 `普通试题管理` 页面。
2. 使用关键词、题型、难度或知识点筛选本次导入的题目。
3. 随机打开几道题检查：
   - 题干是否完整。
   - 选项是否换行正确。
   - 答案格式是否符合对应题型。
   - 解析是否准确。
   - 难度是否符合预期。
   - 知识点关联是否正确。
4. 对不符合要求的题目进行编辑或删除。
5. 再将确认无误的题目加入试卷。

## 8. 常见问题

### 8.1 提示「请填写 DeepSeek API Key 和生成要求」

原因：API Key 或生成要求为空。

处理：补充 API Key 和明确的出题提示词后重新提交。

### 8.2 页面提示「操作失败，请检查请求参数或服务器返回信息」

原因：这是前端没有解析到标准 JSON 响应时显示的兜底提示，通常表示后端发生了 PHP 致命错误、curl 扩展缺失、DeepSeek 响应结构异常，或服务器在 JSON 前输出了错误内容。

处理：

1. 先查看浏览器开发者工具 Network 中 `index.php?exam-master-questions-deepseekimport` 请求的 Response。
2. 再查看服务器日志；本插件会把 DeepSeek 导入过程中的失败写入 `data/deepseek_import_error.log`，也会同步写入 PHP `error_log`。
3. 如果日志中提示缺少 `curl_init`，请启用 PHP curl 扩展。
4. 如果日志中包含 DeepSeek API 返回内容，请根据 HTTP 状态码、模型名、Key、余额或响应 JSON 结构修正后重试。

### 8.3 提示「DeepSeek 调用失败」

可能原因：

- API Key 错误或失效。
- DeepSeek 账户余额不足。
- 模型名称不存在或无权限。
- 服务器无法访问 DeepSeek API。
- PHP 没有启用 curl 扩展。

处理：

1. 在 DeepSeek Platform 检查 API Key 和余额。
2. 使用服务器命令测试网络连通性。
3. 检查 PHP curl 扩展。
4. 将模型名改为当前可用模型，例如 `deepseek-chat`。

### 8.4 提示「DeepSeek 未返回有效的试题 json」

原因：模型返回内容不是插件需要的 JSON 对象，或没有 `questions` 数组。

处理：

- 减少生成数量。
- 提高最大输出 Token。
- 在提示词中强调「只返回 JSON，不要返回 Markdown」。
- 明确写出 JSON 字段名和格式。

### 8.5 导入后选项显示不符合预期

原因：DeepSeek 返回的 `options` 字段不规范，或题型本身不需要选项。

处理：

- 单选、多选、判断题建议让模型返回数组格式选项。
- 问答题、填空题建议让 `options` 返回空数组，`select_number` 返回 0。

### 8.6 知识点没有关联成功

原因：`knowsid` 中的 ID 在 PHPEMS 中不存在，或使用了中文逗号、空格等不规范格式。

处理：

- 在后台确认知识点 ID 存在。
- 多个知识点使用英文逗号，例如 `12,15,18`。
- 可以先在页面填写「默认知识点ID」，让所有题目使用同一组知识点。

## 9. 安全建议

- 不要把 API Key 写入提示词、题干、解析或公开文档。
- 不要将管理员真实 API Key 截图或发送给无关人员。
- 建议先少量生成，审核通过后再批量生成。
- AI 生成内容可能存在事实错误，正式考试前必须人工审核。
- 对高风险考试或资格认证题库，建议增加二次审核流程。

## 10. 最佳实践

- 每次生成只聚焦一个知识点或一个小章节，题目质量更稳定。
- 在提示词中明确题型 ID、答案格式和难度分布。
- 对选择题明确选项数量和答案字母格式。
- 对问答题明确评分要点和解析深度。
- 先生成 3 到 5 道试题验证格式，再扩大到 20 道以上。
- 导入后及时在题库中复核，避免错误题目进入正式试卷。

## 11. 官方文档参考

- DeepSeek API 快速开始：`https://api-docs.deepseek.com/zh-cn/`
- DeepSeek JSON Output：`https://api-docs.deepseek.com/zh-cn/guides/json_mode`
- DeepSeek Platform：`https://platform.deepseek.com/`
