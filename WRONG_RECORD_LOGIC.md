# 错题本记录逻辑说明

## 入口页面

`/index.php?exam-app-record` 对应 `app/exam/controller/record.app.php` 的 `index()` 方法。该页面只展示当前登录用户、当前科目下已经写入 `record` 表，并且题目状态为启用的错题记录。

## 自动收录开关

后台“考试 / 模块设置 / 错题收录”控制 `appsetting.autorecord`。只有该开关开启时，系统才会在客观题交卷后自动把错题写入错题本。主观题需要人工阅卷，系统配置说明中也明确主观题不自动收录。

## 交卷评分与错题识别

交卷时，各考试/练习控制器先读取用户答案并调用 `exam.cls.php` 的 `markscore()`：

1. `markscore()` 遍历普通试题和题帽子题。
2. 非主观题会按标准答案计算得分。
3. 答错或不得分的客观题会把 `questionid` 放入返回值 `wrongids`。
4. 需要人工阅卷的题型只标记 `needhand`，不会进入 `wrongids`。
5. 评分成功后写入 `examhistory` 并删除考试会话。

## 写入错题本

控制器拿到 `markscore()` 返回值后，如果 `wrongids` 非空且自动收录开启，会调用 `favor.cls.php` 的 `addRecords($userid, $ids, $subjectid)`：

1. 按“用户 + 题目 + 科目”检查是否已存在错题记录，避免重复写入。
2. 不存在时插入 `record` 表，字段包括 `recordquestionid`、`recorduserid`、`recordsubjectid`、`recordtime`。
3. 本次修复后，新增错题后会立即刷新 `recorddata` 缓存，保证“错题强化练习/抽题”能立刻使用最新错题数据。

## 本次发现并修复的问题

1. 强化练习 `exam-app-exercise-score` 评分后虽然能识别 `wrongids`，但没有调用 `addRecords()`，因此练习中答错的题不会出现在 `/index.php?exam-app-record`。
2. 手机端强化练习存在相同遗漏。
3. `addRecords()` 原先只按“用户 + 题目”去重，跨科目复用题目 ID 时可能导致当前科目不写入错题；现改为按“用户 + 题目 + 科目”去重。
4. 新增错题后原先不刷新 `recorddata`，导致错题强化练习依赖的缓存滞后；现新增记录后即时刷新。

## 排查建议

如果页面仍然没有错题，请依次检查：

1. 后台“错题收录”是否开启。
2. 交卷入口是否走了已接入自动收录的控制器。
3. 答错题是否为客观题；主观题不自动进入错题本。
4. `record` 表中是否有当前用户、当前科目的记录。
5. 题目 `questionstatus` 是否为 `1`，因为错题本列表只展示启用题目。
