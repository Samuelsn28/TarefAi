<?php

require_once __DIR__ . '/connection.php';

class Query {

	private string $sql;
	private array $params;
	private string $types;

	public function __construct(string $sql) {
		if (trim($sql) == '') {
			throw new InvalidArgumentException('SQL to Query class can\'t be empty.');
		}

		$this->sql = $sql;
	}

	public function where(string $column, mixed $value, string? operation): Query {
		array_push($this->params[], $value);
		$this->updateParamsTypes([$value]);

		$this->addStatement("WHERE $column=?");
	}

	public function orderBy(): Query {

	}

	public function execute() {
		global $connection;

		$preparedStatement = mysqli_prepare($this->sql);
	}

	private function updateParamsTypes(array $values) {
		foreach ($values as $v) {
		    $this->types .= match (gettype($v)) {
		        "integer" => "i",
		        "double"  => "d",
		        default   => "s",
		    };
		}
	}

	private function addStatement(string $statement) {
		$this->sql = trim($sql) . ' ' . trim($statement);
	}

}

?>