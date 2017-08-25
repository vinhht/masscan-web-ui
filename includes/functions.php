<?php
session_start();

function pre_var_dump($var)
{
    echo "<pre>";
    var_dump($var);
    echo "</pre>";
}
function getPdo()
{
    try {
        $db = new PDO(DB_DRIVER.":host=".DB_HOST.";port=3306;dbname=".DB_DATABASE, DB_USERNAME, DB_PASSWORD);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $q = "SELECT * FROM information_schema.TABLES";
            $stmt = $db->query($q);
            $tmp = $stmt->fetch($q);
            return $db;
        }
        catch (PDOException $pdoe) {
            include DOC_ROOT.'includes/install.php';
            exit();
        }
    }
    catch (PDOException $pdoe) {
        if (substr(php_sapi_name(), 0, 3) == 'cli'):
            echo $pdoe->getMessage();
            die;
        endif;
        if (strpos($pdoe->getMessage(), 'Access denied for user') || strpos($pdoe->getMessage(), ' getaddrinfo failed') || strpos($pdoe->getMessage(), 'not find driver') || strpos($pdoe->getMessage(), 'does not exist')):
            include DOC_ROOT.'includes/setup.php';
        else:
            include DOC_ROOT.'includes/error.php';
        endif;
        exit();
    }
}

function LastDateofMonth($month, $year) {
    switch ($month) {
        case 4:
        case 6:
        case 9:
        case 11:
            return 30;
        case 2:
            if($year % 4 == 0) {
                if($year % 100 == 0) {
                    if($year % 400 == 0)
                        return 29;
                    else
                        return 28;
                } else {
                    return 29;
                }
            } else {
                return 28;
            }
        default:
            return 31;
    }
}

function checkTabletime($table1, $table2) {
    $year1 = (int)(substr($table1, 5, 4));
    $month1 = (int)substr($table1, 9, 2);
    $day1 = (int)substr($table1, 11, 2);

    $year2 = (int)substr($table2, 5, 4);
    $month2 = (int)substr($table2, 9, 2);
    $day2 = (int)substr($table2, 11, 2);

    // print $day1 . " " . $month1 . " " . $year1;
    // print $day2 . " " . $month2 . " " . $year2;

    if($year2 == $year1) {
        if($month1 == $month2 && $day2 - $day1 == 1) {
            return True;
        } elseif($month2 - $month1 == 1) {
            if ($day2 == 1 && $day1 == LastDateofMonth($month1, $year1)) {
                return True;
            }
        }
    } elseif ($year2 - $year1 == 1) {
        if($day1 == 31 && $month1 == 12 && $day2 = 1 && $month2 == 1) {
            return True;
        }
    }

    return False;
}

function tableExists($pdo, $table) {
    try {
        $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
    } catch (Exception $e){
        return FALSE;
    }

    return $result != FALSE;
}

function browse($filter, $export = false)
{
    $db = getPdo();
    $records_per_page = (int)$filter['rec_per_page'];
    if (isset($filter['page']) && $filter['page'] > 1):
        $page = (int)$filter['page'];
    else:
        $page = 1;
    endif;
    $from = ($page - 1) * $records_per_page;

    // $filter['time'] = 'data_XXXXXXXX'
    if (strlen($filter['time']) > 5) {
        $_SESSION['id'] = $filter['time'];
    } else {
        $filter['time'] = $_SESSION['id'];
    }

    $arr = array();
    // Check table exists or not
    if (($filter['time'] != 'data_allallall') && (!tableExists($db, $filter['time']))) {
        return '';
    }
    if ($filter['time'] != 'data_allallall') {
        $arr[] = 'data';
        $arr[] = $filter['time'];
    } else {
        $arr = listTables();
    }

    $len_arr = sizeof($arr);
    if ($len_arr <= 1)
        return '';

    $query1 = $query2 = '';

    for ($i = 1; $i < $len_arr; $i++) {

        $q1 = "SELECT ip AS ipaddress, port_id, protocol, state, reason, service, banner, title, scanned_ts";
        $q2 = "SELECT DISTINCT * ";

        $q = " FROM " . $arr[$i] . " WHERE 1 = 1";

        if (!empty($filter['ip'])):
            list($start_ip, $end_ip) = getStartAndEndIps($filter['ip']);
            $q .= " AND (ip >= $start_ip AND ip <= $end_ip)";
        endif;
        if (isset($filter['port']) && (int) $filter['port'] > 0 && (int) $filter['port'] <= 65535):
            $q .= " AND port_id = " . (int) $filter['port'];
        endif;
        if (!empty($filter['protocol'])):
            $q .= " AND protocol = '" . $filter['protocol'] . "'";
        endif;
        if (!empty($filter['state'])):
            $q .= " AND state = '" . $filter['state'] . "'";
        endif;
        if (!empty($filter['service'])):
            $q .= " AND service = '" . $filter['service'] . "'";
        endif;
        if (!empty($filter['banner'])):
            if ((int)$filter['exact-match'] === 1):
                if (DB_DRIVER == 'pgsql') {
                    $q .= " AND (banner LIKE '%" . $filter['banner'] . "' OR title LIKE '%" . $filter['banner'] . "%')";
                } else {
                    $q .= " AND (banner LIKE BINARY \"%" . $filter['banner'] . "%\" OR title LIKE BINARY \"%" . $filter['banner'] . "%\")";
                }
            else:
                if (DB_DRIVER == 'pgsql') {
                    $banner = implode(' | ', explode(" ", $filter['banner']));
                    $q .= " AND searchtext @@ to_tsquery('".$banner."')";
                } else {
                    $q .= " AND match(title, banner) AGAINST (\"" . $filter['banner'] . "\" IN NATURAL LANGUAGE MODE)";
                }
            endif;
        endif;
        if (!empty($filter['text'])):
            if (DB_DRIVER == 'pgsql') {
                $banner = implode(' | ', explode(" ", $filter['text']));
                $q .= " AND searchtext @@ to_tsquery('".$banner."')";
            } else {
                $q .= " AND (match(title, banner) AGAINST (\"" . $filter['text'] . "\" IN NATURAL LANGUAGE MODE)
                    OR service = \"" . $filter['text'] . "%\"
                    OR protocol = \"" . $filter['text'] . "%\"
                    OR port_id = \"" . (int) $filter['text'] . "%\")";
            }
        endif;

        $query1 .= $q1 . $q;
        $query2 .= $q2 . $q;

        if ($i != $len_arr - 1) {
            $query1 .= " UNION ALL ";
            $query2 .= " UNION ALL ";
        }
    }

    if (isset($start_ip)):
        $q3 = " ORDER BY ipaddress ASC, scanned_ts DESC";
    else:
        $q3 = " ORDER BY scanned_ts DESC";
    endif;
    if (!$export):
        $q4 = " LIMIT $records_per_page OFFSET $from";
    else:
        $q4 = "";
    endif;

    $query1 = "SELECT * FROM (" . $query1 . ") a" . $q3 . $q4;
    $query2 = "SELECT COUNT(*) as total_records FROM (" . $query2 . ") b";

    try {
        $stmt = $db->query($query1);
    }
    catch(PDOException $ex) {
        echo "An Error occured!";
        echo $ex->getMessage();
        die;
    }
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($export) {
        return $data;
    }
    $tmp2 = $db->query($query2);
    $total = $tmp2->fetch(PDO::FETCH_ASSOC);
    $to = $from + $records_per_page < $total['total_records'] ? $from + $records_per_page : $total['total_records'];
    $pages = $total ['total_records'] > 1 ? ceil($total ['total_records'] / $records_per_page) : 0;
    return array(
        'data' => $data,
        'pagination' => array(
            'page' => $page,
            'pages' => $pages,
            'records' => $total ['total_records'],
            'from' => ++$from,
            'to' => $to)
    );
}

function getStartAndEndIps($ip)
{
    $start_ip   = '';
    $end_ip     = '';
    $ip         = trim($ip, '.');
    $p          = explode('.', trim($ip));
    for ($i = 0; $i < 4; $i++):
        if ($i > 0):
            $start_ip .= '.';
            $end_ip .= '.';
        endif;
        if (isset($p[$i])):
            $start_ip .= $p[$i];
            $end_ip .= $p[$i];
        else:
            $start_ip .= "0";
            $end_ip .= "255";
        endif;
    endfor;
    return array(ip2long($start_ip), ip2long($end_ip));
}

function listTables()
{
    $db = getPdo();
    $q = "SHOW TABLES FROM masscan";
    $stmt = $db->query($q);
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $tables;
}

function createTable($table_name)
{
    try {
        $db = getPdo();
        $sql = "DROP TABLE IF EXISTS `" . $table_name . "`;

        CREATE TABLE IF NOT EXISTS `" . $table_name . "` (
            `id` bigint(20) unsigned NOT NULL,
            `ip` int(10) unsigned NOT NULL DEFAULT '0',
            `port_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
            `scanned_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `protocol` enum('tcp','udp') NOT NULL,
            `state` varchar(10) NOT NULL DEFAULT '',
            `reason` varchar(255) NOT NULL DEFAULT '',
            `reason_ttl` int(10) unsigned NOT NULL DEFAULT '0',
            `service` varchar(100) NOT NULL DEFAULT '',
            `banner` text NOT NULL,
            `title` text NOT NULL
        ) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;

        ALTER TABLE `" . $table_name . "` ADD PRIMARY KEY (`id`), ADD KEY `scanned_ts` (`scanned_ts`), ADD KEY `ip` (`ip`), ADD FULLTEXT KEY `banner` (`banner`,`title`);
        ALTER TABLE `" . $table_name . "` MODIFY `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=1;";

        $db->exec($sql);

    } catch (PDOException $e) {
        echo $e->getMessage();
    }
}