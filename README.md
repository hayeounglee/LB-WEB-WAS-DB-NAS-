```bash
[사용자]
    ↓
┌─────────────────────────────┐
│           main              │
│  - LB (HAProxy)             │
└────────┬──────────┬─────────┘
         │          │
    ┌────┘          └────┐
    ↓                    ↓
┌─────────┐        ┌─────────┐
│ server1 │        │ server2 │
│ WEB1    │        │ WEB2    │
│ (php)   │        │ (php)   │
│ NFS     │        │ NFS     │
│클라이언트│        │클라이언트│
└────┬────┘        └────┬────┘
     │                  │
     └────────┬──────────┘
              ↓
┌─────────────────────────────┐
│           server3           │
│  - DB (MariaDB)             │
│  - NFS 서버                 │
└─────────────────────────────┘
```

## 0. 사전 준비

### 모든 서버 공통

```bash
# DNS를 main으로 지정
# 클라이언트가 www.linux123.com 치면 LB로 가야 하기 때문

nmcli con mod eth0 ipv4.dns 192.168.10.10
nmcli con up eth0
```

```bash
#  /etc/hosts

127.0.0.1   localhost
192.168.10.10   main.linux123.com    main
192.168.10.20   server1.linux123.com server1
192.168.10.30   server2.linux123.com server2
192.168.10.40   server3.linux123.com server3
```

### main — DNS 서버 설정

```bash
vi /var/named/linux123.com.zone
```

```bash
$TTL 1D
@   IN  SOA  linux123.com.  root.linux123.com. (
                2024010101
                1D
                1H
                1W
                3H )
@       IN  NS   linux123.com.
@       IN  A    192.168.10.10
www     IN  A    192.168.10.10
main    IN  A    192.168.10.10
server1 IN  A    192.168.10.20
server2 IN  A    192.168.10.30
server3 IN  A    192.168.10.40

```

### 

### named.conf에 zone 등록 + forwarder 추가

```bash
vi /etc/named.conf
```

```bash
# options 블록 안에:
forwarders { 8.8.8.8; };

# zone 블록 추가:
zone "linux123.com" IN {
    type master;
    file "linux123.com.zone";
};
```

```bash
# 서비스 시작 + 방화벽
systemctl enable --now named
firewall-cmd --permanent --add-service=dns
firewall-cmd --reload

# 확인
nslookup linux123.com 192.168.10.10
# Address: 192.168.10.10 나오면 OK
```

## 1. server3 — NFS 서버 설정

<aside>

### **NFS가 왜 필요한가**

DB는 데이터(내용)를 저장하는 곳이고, NFS는 소스코드(index.php)를 공유하는 곳이다.

NFS 없이 server1, server2가 각자 소스코드를 관리하면 코드 수정 시 모든 서버에 개별 접속하여 수정해야 한다. 서버 수가 늘어날수록 관리 부담이 증가한다.

NFS를 사용하면 server3 한 곳에서 소스코드를 관리하고, server1과 server2가 이를 마운트하여 공유한다. 소스코드를 한 번만 수정해도 모든 웹 서버에 즉시 반영된다.

</aside>

```bash
# 공유 디렉토리 생성
mkdir -p /webshare
```

```bash
# exports 설정
vi /etc/exports

/webshare  192.168.10.0/24(rw,no_root_squash)
```

```bash
# 서비스 시작 + 방화벽
systemctl enable --now nfs-server
firewall-cmd --permanent --add-service=nfs
firewall-cmd --reload

# 확인
exportfs -v
```

## 2. server3 — DB 설정

<aside>

### **DB가 왜 필요한가**

웹 서버(PHP)는 요청을 처리하고 화면을 출력하는 역할을 담당한다.
그러나 **데이터를 저장하고 조회하기 위해서**는 별도의 DB가 필요하다.

본 실습에서는 **server1, server2 중 어느 서버가 요청을 처리했는지 DB에 기록**하여 **부하 분산(roundrobin)이 실제로 동작하는지 확인**하는 용도로 활용한다.

</aside>

<aside>

### DB 사용자 계정이 왜 필요한가

MariaDB는 기본적으로 root 계정의 원격 접속을 허용하지 않는다.

**PHP(server1/server2) → server3 DB 접속 시도
→ root 계정은 원격 접속 불가
→ 원격 접속이 허용된 전용 계정 필요**

따라서 원격 접속 전용 계정을 생성하고 권한을 부여한다.

이를 통해 server1, server2가 **server3** DB에 원격으로 접속할 수 있게 된다.

</aside>

```bash
# 서비스 시작 + 방화벽
systemctl enable --now mariadb
firewall-cmd --permanent --add-service=mysql
firewall-cmd --reload
```

```bash
# DB/유저 생성
CREATE DATABASE testdb;
CREATE USER 'webuser'@'%' IDENTIFIED BY 'centos';
GRANT ALL PRIVILEGES ON testdb.* TO 'webuser'@'%';
FLUSH PRIVILEGES;

USE testdb;
CREATE TABLE test (
    id INT AUTO_INCREMENT PRIMARY KEY,
    msg VARCHAR(255)
);
exit
```

```jsx
# 확인
mysql -u webuser -pcentos testdb -e "SHOW TABLES;"
```

## 3. server3 — 웹 소스 파일 생성

```bash
mkdir -p /webshare/cgi-bin
vi /webshare/index.php
```

```bash
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
```

## 4.  main — LB(HAProxy) 설정

```bash
vi /etc/haproxy/haproxy.cfg
```

```bash
# frontend/backend 수정:

frontend main
    bind *:80
    default_backend app

backend app
    balance roundrobin
    server app1 192.168.10.20:80 check
    server app2 192.168.10.30:80 check
```

```bash
# 문법 확인
# Configuration file is valid 나오면 OK

haproxy -f /etc/haproxy/haproxy.cfg -c
```

```bash
# 서비스 시작 + 방화벽

systemctl enable --now haproxy
firewall-cmd --permanent --add-service=http
firewall-cmd --reload
```

```bash
# VirtualHost 설정 (DocumentRoot → NFS 마운트 경로로 변경)

vi /etc/httpd/conf.d/vhost.conf
```

```bash
<VirtualHost *:80>
    ServerAdmin webmaster@linux123.com
    ServerName linux123.com
    ServerAlias www.linux123.com
    DocumentRoot "/webshare"
    ErrorLog "/var/log/httpd/linux123_error_log"
    CustomLog "/var/log/httpd/linux123_access_log" common
    <Directory /webshare>
       Require all granted
       Options Indexes Includes
       AllowOverride AuthConfig
    </Directory>
    ScriptAlias /cgi-bin/ /webshare/cgi-bin/
</VirtualHost>
```

```bash
# 서비스 시작 + 방화벽

systemctl enable --now httpd
firewall-cmd --permanent --add-service=http
firewall-cmd --reload
```

## 5. server1, server2 — WEB 서버 설정

```bash
# NFS 마운트

mkdir -p /webshare
mount 192.168.10.40:/webshare /webshare

# 부팅 시 자동 마운트
echo "192.168.10.40:/webshare /webshare nfs defaults 0 0" >> /etc/fstab
```

## 6. 최종 확인

```bash
# 웹페이지
http://linux123.com

# DB INSERT 확인
mysql -u webuser -pcentos testdb -e "SELECT * FROM test;"
```

### 1)  정상 동작 확인

- main → server1 → server2 → server3 순으로 웹페이지 접속(**2번 반복🔁**)

**✅ 새로고침 시 HOST가 server1 ↔ server2 라운드로빈으로 돌아가면서 호출하는거 확인**

**✅ DB 기록 테이블에 양쪽 서버 기록이 쌓이는지 확인**

<img width="957" height="794" alt="image" src="https://github.com/user-attachments/assets/3958d44f-9897-4212-aa73-c75c11472315" />


<img width="866" height="787" alt="image" src="https://github.com/user-attachments/assets/e3234b6e-4026-4a93-98d5-93a00962886e" />


<img width="1005" height="782" alt="image" src="https://github.com/user-attachments/assets/fbd8485a-ed59-4960-899b-d0f0875b3154" />


<img width="1200" height="825" alt="image" src="https://github.com/user-attachments/assets/e8f891e0-3f6e-4165-b29c-74a81e849f5d" />


<img width="748" height="268" alt="image" src="https://github.com/user-attachments/assets/8e79b44f-d194-4b90-83a8-b4c64c5f3879" />


### **2) 장애 복구 확인 (server1 다운)**

- server1 종료(poweroff) 후 main → server2 → server3 순으로 웹페이지 접속

**✅ server2로만 서비스가 지속되는지 확인**

**✅ DB 및 NFS 연결이 유지되는지 확인**

<img width="1059" height="793" alt="image" src="https://github.com/user-attachments/assets/355f738d-b3fb-4d2a-8d38-44dd12045a92" />


<img width="928" height="787" alt="image" src="https://github.com/user-attachments/assets/183d92f5-1a0a-4560-9172-b486947f8935" />


<img width="1094" height="821" alt="image" src="https://github.com/user-attachments/assets/5226d985-f707-47b0-ace0-cab417fcde02" />

<img width="673" height="338" alt="image" src="https://github.com/user-attachments/assets/c61ea185-094a-4f17-bead-0cf8d21e0644" />

