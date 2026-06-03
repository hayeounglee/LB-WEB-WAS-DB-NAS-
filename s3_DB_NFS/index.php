<?php
echo "<h1>LB PHP + DB TEST</h1>";
$conn = mysqli_connect("192.168.10.40","webuser","centos","testdb");
if (!$conn) {
    die("DB FAIL");
}
$host = gethostname();
$ip = $_SERVER['SERVER_ADDR'];
$time = date("Y-m-d H:i:s");
mysqli_query($conn, "INSERT INTO test (msg) VALUES ('$host - $ip - $time')");
echo "<br>HOST : $host";
echo "<br>IP : $ip";
echo "<br>TIME : $time";

// DB 내용 출력
echo "<hr><h2>DB 기록</h2>";
$result = mysqli_query($conn, "SELECT * FROM test ORDER BY id DESC");
echo "<table border='1'>";
echo "<tr><th>ID</th><th>MSG</th></tr>";
while ($row = mysqli_fetch_assoc($result)) {
    echo "<tr><td>".$row['id']."</td><td>".$row['msg']."</td></tr>";
}
echo "</table>";
?>
