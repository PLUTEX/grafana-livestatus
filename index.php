<?php
$script_path = dirname($_SERVER['SCRIPT_NAME']);
$relative_uri = $_SERVER['REQUEST_URI'];
if (substr($relative_uri, 0, strlen($script_path)) == $script_path)
    $relative_uri = substr($relative_uri, strlen($script_path));

function open_livestatus_socket() {
    $sock = stream_socket_client("unix://${_SERVER['OMD_ROOT']}/tmp/run/live", $errno, $errstr);
    if(!$sock)
        error("Failed to open Livestatus socket: $errstr ($errno)");
    return $sock;
}

function error($msg = NULL) {
    header('HTTP/1.1 500 Internal Server Error');
    if($msg)
        echo $msg;
    exit;
}

function execute_query($target) {
    $table = $target['target'];
    $filters = $target['payload']['filters'] ?? [];
    $column_names = $target['payload']['columns'] ?? [];

    $query = "GET $table\nOutputFormat: json\n";
    foreach($filters as $filter)
	$query .= "Filter: $filter\n";
    if(!empty($column_names))
	$query .= "Columns: " . implode($column_names, ' ') . "\n";
    $query .= "\n";
    $lq = open_livestatus_socket();
    fwrite($lq, $query);
    $buf = "";
    while(!feof($lq))
	$buf .= fgets($lq);
    $rows = json_decode($buf, TRUE);
    unset($buf);
    if (count($rows) == 0)
	return;
    $columns = [];
    if(empty($column_names))
	$column_names = array_shift($rows);
    foreach(array_combine($column_names, $rows[0]) as $column_name => $item)
	$columns[] = ['text' => $column_name, 'type' => is_int($item)? 'number' : 'string'];
    return ['columns' => $columns, 'rows' => $rows, 'type' => 'table'];
}

switch ($relative_uri) {
    case '/':
        if ($_SERVER['REQUEST_METHOD'] != 'GET') {
            header('HTTP/1.1 405 Method Not Allowed');
        }
        header('Content-Type: text/plain');
        echo 'OK';
        break;
    case '/query':
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            exit;
        }
        $body = file_get_contents('php://input');
        $body = json_decode($body, TRUE);

	$results = array_filter(array_map("execute_query", $body['targets']));

        header('Content-Type: application/json');
        echo json_encode($results);
        break;
    case '/search':
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            exit;
        }

        $body = file_get_contents('php://input');
        $tables = [];
        $lq = open_livestatus_socket();

        fwrite($lq, "GET columns\nColumns: table\n\n");
        socket_shutdown($lq, 1);
        while(!feof($lq)) {
            $table = trim(fgets($lq));
            if(!in_array($table, $tables))
                $tables[] = $table;
        }

        header('Content-Type: application/json');
        echo json_encode($tables);
        break;
    case '/variable':
        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            exit;
        }

        $body = file_get_contents('php://input');
        $body = json_decode($body, TRUE);
	$payload = $body["payload"];
        if (empty($body["payload"])) {
            header('HTTP/1.1 400 Bad Request');
            exit;
        }
	$result = execute_query($payload);

        header('Content-Type: application/json');
        echo json_encode(array_map(function($value) { return array("__text" => $value, "__value" => $value); }, $result["rows"]));
        break;
    default:
        header('HTTP/1.1 404 Not Found');
        break;
}

