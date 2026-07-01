<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<title>{x2;if:$content['contenttitle']}{x2;$content['contenttitle']} - {x2;endif}{x2;if:$course['coursetitle']}{x2;$course['coursetitle']} - {x2;endif}{x2;if:$cs['cstitle']}{x2;$cs['cstitle']} - {x2;endif}{x2;if:$doc['doctitle']}{x2;$doc['doctitle']} - {x2;endif}{x2;if:$ask['asktitle']}{x2;$ask['asktitle']} - {x2;endif}{x2;if:$survey['svytitle']}{x2;$survey['svytitle']} - {x2;endif}{x2;if:$ce['cetitle']}{x2;$ce['cetitle']} - {x2;endif}{x2;if:$basic['basic']}{x2;$basic['basic']} - {x2;endif}{x2;if:$sessionvars['examsession']}{x2;$sessionvars['examsession']} - {x2;endif}{x2;if:$cat['catname']}{x2;$cat['catname']} - {x2;endif}{x2;if:$app['appname']}{x2;$app['appname']} - {x2;elseif:$apps[$_app]['appname']}{x2;$apps[$_app]['appname']} - {x2;endif}PHPEMS模拟考试系统</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=0.75, maximum-scale=1.0, user-scalable=no">
	<link rel="stylesheet" type="text/css" href="files/public/css/bootstrap.css" />
	<link rel="stylesheet/less" type="text/css" href="files/public/css/pe.less" />
	<link rel="stylesheet" href="files/public/css/swiper.min.css">
	<script src="files/public/js/less.min.js"></script>
	<script src="files/public/js/jquery.min.js"></script>
	<script src="files/public/js/swiper.min.js"></script>
	<script src="files/public/js/bootstrap.min.js"></script>
	<script src="files/public/js/bootstrap-datetimepicker.js"></script>
	<script src="files/public/js/all.fine-uploader.min.js"></script>
	<script src="files/public/js/ckeditor/ckeditor.js"></script>
	<script src="files/public/js/pe.app.js"></script>
</head>