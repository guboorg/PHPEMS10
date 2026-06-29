<?php
class LtDbConnectionAdapterMysql implements LtDbConnectionAdapter
{
	public function connect($connConf)
	{
		return mysqli_connect($connConf["host"], $connConf["username"], $connConf["password"], isset($connConf["dbname"]) ? $connConf["dbname"] : null, $connConf["port"]);
	}

	public function exec($sql, $connResource)
	{
		return mysqli_query($connResource, $sql) ? mysqli_affected_rows($connResource) : false;
	}

	public function query($sql, $connResource)
	{
		$result = mysqli_query($connResource, $sql);
		$rows = array();
		while($result && $row = mysqli_fetch_assoc($result))
		{
			$rows[] = $row;
		}
		return $rows;
	}

	public function lastInsertId($connResource)
	{
		return mysqli_insert_id($connResource);
	}

	public function escape($sql, $connResource)
	{
		return mysqli_real_escape_string($connResource, $sql);
	}
}