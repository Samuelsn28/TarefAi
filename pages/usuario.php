<?php

require_once("../lib/database/queryable.php");

class Usuario extends Queryable {

	protected function getTable(): string {
		return "users_tb";
	}
}

?>