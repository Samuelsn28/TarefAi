<?php

$ip = "localhost";
$user = "root";
$password = "";
$database = "tarefai_db";

$connection = mysqli_connect($ip, $user, $password, $database) or failConnection("A conexão com o banco de dados falhou. IP: $ip; user: $user; password: $password; database: $database.");

function failConnection(string $message) {
	error_log($message);

	die("<h1>Um erro inesperado ocorreu. Tente mais tarde.</h1>");
}