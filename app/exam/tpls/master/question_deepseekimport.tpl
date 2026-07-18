{x2;include:header}
<body>
{x2;include:nav}
<div class="container-fluid">
	<div class="row-fluid">
		<div class="main">
			<div class="col-xs-2 leftmenu">{x2;include:menu}</div>
			<div id="datacontent">
				<div class="box itembox" style="margin-bottom:0px;border-bottom:1px solid #CCCCCC;">
					<div class="col-xs-12">
						<ol class="breadcrumb">
							<li><a href="index.php?{x2;$_app}-master">{x2;$apps[$_app]['appname']}</a></li>
							<li><a href="index.php?{x2;$_app}-master-questions">试题管理</a></li>
							<li class="active">DeepSeek 生成导入</li>
						</ol>
					</div>
				</div>
				<div class="box itembox" style="padding-top:10px;margin-bottom:0px;">
					<h4 class="title" style="padding:10px;">DeepSeek 生成试题并导入题库</h4>
					<form class="form-horizontal ajax" action="index.php?exam-master-questions-deepseekimport" method="post">
						<div class="form-group"><label class="control-label col-sm-2">API Key</label><div class="col-sm-8"><input class="form-control" type="password" name="api_key" datatype="*" needle="needle" msg="请填写 DeepSeek API Key" placeholder="sk-..." /></div></div>
						<div class="form-group"><label class="control-label col-sm-2">模型</label><div class="col-sm-4"><input class="form-control" type="text" name="model" value="deepseek-v4-flash" /></div><div class="col-sm-4 help-block">使用 DeepSeek Chat Completions 接口。</div></div>
						<div class="form-group"><label class="control-label col-sm-2">生成数量</label><div class="col-sm-2"><input class="form-control" type="number" name="count" value="5" min="1" max="50" /></div><label class="control-label col-sm-2">最大输出 Token</label><div class="col-sm-2"><input class="form-control" type="number" name="max_tokens" value="4096" min="512" /></div></div>
						<div class="form-group"><label class="control-label col-sm-2">默认题型</label><div class="col-sm-4"><select class="form-control" name="questiontype">{x2;tree:$questypes,questype,qid}<option value="{x2;v:questype['questid']}">{x2;v:questype['questype']}</option>{x2;endtree}</select></div><label class="control-label col-sm-2">默认难度</label><div class="col-sm-2"><select class="form-control" name="questionlevel"><option value="1">1 易</option><option value="2">2 较易</option><option value="3" selected>3 中等</option><option value="4">4 较难</option><option value="5">5 难</option></select></div></div>
						<div class="form-group"><label class="control-label col-sm-2">默认知识点ID</label><div class="col-sm-8"><input class="form-control" type="text" name="knowsid" placeholder="多个知识点ID用英文逗号分隔，如：12,15" /></div></div>
						<div class="form-group"><label class="control-label col-sm-2">生成要求</label><div class="col-sm-8"><textarea class="form-control" rows="10" name="prompt" datatype="*" needle="needle" msg="请填写生成要求" placeholder="例如：围绕高中数学函数单调性，生成单选题和多选题，选项为 A-D，答案解析要说明推导过程。"></textarea></div></div>
						<div class="form-group"><div class="col-sm-offset-2 col-sm-8"><p class="help-block">本插件按 DeepSeek 官方文档使用 Chat Completions、response_format json_object 获取结构化 JSON，再写入 PHPEMS 题库字段：题干、选项、选项数、答案、解析、难度、题型和知识点。详细使用说明见 docs/plugins/deepseek_question_import.md。</p></div></div>
						<div class="form-group"><div class="col-sm-offset-2 col-sm-8"><button class="btn btn-primary" type="submit">生成并导入</button> <a class="btn btn-default" href="index.php?exam-master-questions">返回</a><input type="hidden" name="importquestion" value="1" /><input type="hidden" name="page" value="{x2;$page}" /></div></div>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
{x2;include:footer}
</body>
</html>
