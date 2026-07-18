# 填空题导入 CSV 示例：音标小学英语词汇

`import_question_fill_blank_phonetic.csv` 是一个可直接用于试题导入的 GBK 编码 CSV 文件，内容对应以下题目：

- 题型：填空题（默认题型 ID：`5`）
- 知识点：`1:音标-小学英语词汇`（导入字段仅填写知识点 ID：`1`）
- 题干：根据音标填入单词 `/ˈsteɪ.ʃən/`
- 参考答案：`station`

## CSV 字段

该 CSV 不包含表头，字段顺序如下：

| questiontype | question | questionselect | questionselectnumber | questionanswer | questiondescribe | knowsid | questionlevel | isqr | istitle |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `5` | 根据音标填入单词 `/&#712;ste&#618;.&#643;&#601;n/` | 空 | `0` | `station` | 音素拆解与解析 | `1` | `1` | `0` | `0` |

## 编码说明

导入代码会按 `GBK` 转 `UTF-8` 读取 CSV。因为音标字符不完全属于 GBK 字符集，CSV 中的音标使用 HTML 数字实体保存，例如 `&#712;`、`&#618;`、`&#643;`、`&#601;`，导入并展示时可还原为 `/ˈsteɪ.ʃən/`。解析中的换行使用 `<br />`，确保 CSV 文件只有一条物理记录，避免部分上传或编辑工具误处理嵌入式换行。
