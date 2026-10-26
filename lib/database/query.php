<?php

class Query {

	private string $sql; 

	public function __construct(string $sql) {
		if (trim($sql) == '') {
			throw new InvalidArgumentException('SQL to Query class can\'t be empty.');
		}

		$this->sql = $sql;
	}

	public function where(): Query {

	}

	public function orderBy(): Query {

	}

	public function execute() {
		
	}

}

?>