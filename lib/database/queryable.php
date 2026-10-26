<?php

require_once __DIR__ . '/connection.php';

abstract class Queryable {
	
	abstract protected function getTable(): string;

	public static function all(): Query {
		$instance = new static();

		$sql = 'SELECT * FROM ' . $instance->getTable();
		return new Query($sql);		
	}

	public static function insert(): Query {

	}

	public static function update(): Query {

	}

	public static function delete(): Query {

	}

	public static function funciona(): string {

	}

}

?>