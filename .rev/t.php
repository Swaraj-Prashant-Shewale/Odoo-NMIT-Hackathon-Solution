<?php
$sql = sprintf("SELECT * FROM t WHERE a LIKE %s ESCAPE '\' AND b = %s", ':p0', ':p1');
echo "SQL: [", $sql, "]\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE TABLE t (a TEXT, b TEXT)');
$pdo->exec("INSERT INTO t VALUES ('xx','yy')");
try {
  $st = $pdo->prepare($sql);
  $st->execute(['p0'=>'%x%','p1'=>'yy']);
  var_dump($st->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) { echo 'ERROR: ', get_class($e), ': ', $e->getMessage(), PHP_EOL; }
