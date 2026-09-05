<?php
declare(strict_types=1);
$collector=$argv[1]??'';
$src=file_get_contents($collector);
if(!is_string($src)||!preg_match("/->connect\('([^']+)',\s*'([^']+)',\s*'([^']+)',\s*'([^']+)'\)/",$src,$m)) throw new RuntimeException('DB-Konfiguration fehlt');
$db=new mysqli($m[1],$m[2],$m[3],$m[4]); $db->set_charset('utf8mb4');
$sql=<<<'SQL'
DROP TABLE IF EXISTS tl_coh_sensorvalue;
DROP TABLE IF EXISTS tl_coh_sensoreinheiten;
DROP TABLE IF EXISTS tl_coh_sensortypen;
CREATE TABLE tl_coh_sensoreinheiten (
 id tinyint unsigned NOT NULL AUTO_INCREMENT,
 text varchar(50) NOT NULL DEFAULT '',
 PRIMARY KEY(id), UNIQUE KEY text(text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE tl_coh_sensortypen (
 id tinyint unsigned NOT NULL AUTO_INCREMENT,
 text varchar(50) NOT NULL DEFAULT '',
 PRIMARY KEY(id), UNIQUE KEY text(text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO tl_coh_sensoreinheiten(text) VALUES (''),('json');
INSERT INTO tl_coh_sensortypen(text) VALUES ('');
CREATE TABLE tl_coh_sensorvalue (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 tstamp int unsigned NOT NULL DEFAULT 0,
 sensor int unsigned NOT NULL,
 sensorValue longtext NULL,
 einheit tinyint unsigned NOT NULL,
 sensorType tinyint unsigned NOT NULL,
 PRIMARY KEY(id),
 UNIQUE KEY sensor_tstamp(sensor,tstamp),
 KEY tstamp(tstamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
SQL;
$db->multi_query($sql);
do { if($r=$db->store_result())$r->free(); } while($db->more_results()&&$db->next_result());
if($db->errno) throw new RuntimeException($db->error);
echo "SCHEMA_OK\n";
