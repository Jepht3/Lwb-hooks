<?php
/**
 * LE WEB MAX - daily WHMCS database backup (runs with the WHMCS daily cron).
 * Compressed SQL dumps stored outside the web root, 14-day rotation.
 * Very large log tables are dumped as structure only.
 *
 * @category  Maintenance
 * @author    JNK
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function lwm_backup_config()
{
    return [
        'dir' => dirname(ROOTDIR) . '/whmcs_backups',
        'keep' => 14,
        'skip_data' => ['tbllog_register', 'tblsessions', 'tbltransientdata', 'tblmodulelog', 'tblerrorlog'],
    ];
}

function lwm_backup_run()
{
    @set_time_limit(0);
    $cfg = lwm_backup_config();
    $db_host = $db_username = $db_password = $db_name = $db_port = null;
    include ROOTDIR . '/configuration.php';
    if (!is_dir($cfg['dir']) && !mkdir($cfg['dir'], 0700, true)) {
        return ['ok' => false, 'message' => 'Impossible de creer ' . $cfg['dir']];
    }
    $mysqli = @new mysqli($db_host, $db_username, $db_password, $db_name, $db_port ? (int) $db_port : 3306);
    unset($db_password);
    if ($mysqli->connect_errno) {
        return ['ok' => false, 'message' => 'Connexion MySQL impossible : ' . $mysqli->connect_error];
    }
    $mysqli->set_charset('utf8mb4');
    $file = $cfg['dir'] . '/whmcs-' . date('Ymd-His') . '.sql.gz';
    $gz = gzopen($file, 'wb6');
    if (!$gz) {
        return ['ok' => false, 'message' => 'Impossible d ecrire ' . $file];
    }
    chmod($file, 0600);
    gzwrite($gz, "-- WHMCS backup " . date('c') . " ({$db_name})\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tables = [];
    $res = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
    foreach ($tables as $table) {
        $create = $mysqli->query('SHOW CREATE TABLE `' . $table . '`')->fetch_row();
        gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n{$create[1]};\n\n");
        if (in_array($table, $cfg['skip_data'], true)) {
            continue;
        }
        $result = $mysqli->query('SELECT * FROM `' . $table . '`', MYSQLI_USE_RESULT);
        if (!$result) {
            continue;
        }
        $buffer = [];
        $columns = null;
        while ($row = $result->fetch_assoc()) {
            if ($columns === null) {
                $columns = '`' . implode('`,`', array_keys($row)) . '`';
            }
            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : "'" . $mysqli->real_escape_string($value) . "'";
            }
            $buffer[] = '(' . implode(',', $values) . ')';
            if (count($buffer) >= 200) {
                gzwrite($gz, "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $buffer) . ";\n");
                $buffer = [];
            }
        }
        if ($buffer) {
            gzwrite($gz, "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $buffer) . ";\n");
        }
        $result->free();
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- end\n");
    gzclose($gz);
    $mysqli->close();
    $files = glob($cfg['dir'] . '/whmcs-*.sql.gz') ?: [];
    rsort($files);
    foreach (array_slice($files, $cfg['keep']) as $old) {
        @unlink($old);
    }
    return ['ok' => true, 'file' => $file, 'size' => (int) filesize($file), 'message' => 'OK'];
}

add_hook('DailyCronJob', 1, function () {
    try {
        $result = lwm_backup_run();
        if ($result['ok']) {
            logActivity(sprintf('Sauvegarde BD LE WEB MAX : %s (%.1f Mo)', basename($result['file']), $result['size'] / 1048576));
        } else {
            logActivity('Sauvegarde BD LE WEB MAX : ECHEC - ' . $result['message']);
        }
    } catch (\Throwable $e) {
        logActivity('Sauvegarde BD LE WEB MAX : ECHEC - ' . $e->getMessage());
    }
});