#!/usr/bin/env bash
# 시험용 사이트를 새로 설치한다 (이 폴더 아래 www/cm 에 서버 파일을 복사하고 설치 마법사를 자동으로 진행).
# 먼저 MariaDB 를 포트 3307 로 띄워 두세요. (README.md 참고)  화면 빌드(`BASE_PATH=/cm npm run build`)를 www/cm 에 복사하면 화면도 시험할 수 있습니다.
cd "$(dirname "$0")"
ROOT="$(cd ../.. && pwd)"
MYSQL="${CM_MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
"$MYSQL" -uroot -proot -P3307 -h127.0.0.1 -e "DROP DATABASE IF EXISTS cmtest; CREATE DATABASE cmtest CHARACTER SET utf8mb4;"
mkdir -p www/cm/data www/cm/api www/cm/install www/cm/migrations
cp -r "$ROOT/server/api/." www/cm/api/ && cp -r "$ROOT/server/install/." www/cm/install/ && cp -r "$ROOT/server/migrations/." www/cm/migrations/
rm -rf www/cm/data/* www/cm/.htaccess
node install.mjs http://127.0.0.1:8090/cm
