<?php
// PHP 5-compatible Modpoll Web UI converted from Tkinter app
// Uses embedded modpoll.exe in this folder (not system one)

// ---------------------------- CONFIG ---------------------------------
$APP_DIR = __DIR__;
$MODPOLL_PATH = $APP_DIR . DIRECTORY_SEPARATOR . 'modpoll.exe';
$MODPOLL_DOWNLOAD_URL = 'https://github.com/spenz91/ModpollingTool/releases/download/modpollv2/modpoll.exe';
$PID_FILE = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'modpoll_runner.pid';

// Optional MySQL settings
$DB_HOST = '127.0.0.1';
$DB_NAME = 'iw_plant_server3';
$DB_USER_PRIMARY = 'iwmac';
$DB_PASS_PRIMARY = '';
$DB_USER_FALLBACK = 'root';
$DB_PASS_FALLBACK = '';

// ---------------------------- HELPERS --------------------------------
if (!function_exists('http_response_code')) {
    function http_response_code($code = null) {
        static $httpCode = 200;
        if ($code !== null) { $httpCode = $code; header('X-PHP-Response-Code: '.$code, true, $code); }
        return $httpCode;
    }
}

function respond_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
}

function ensure_modpoll_exists($path, $url) {
    if (file_exists($path)) {
        return array('ok' => true, 'path' => $path, 'downloaded' => false);
    }
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $temp = $path . '.download';
    $ctx = stream_context_create(array(
        'http' => array('timeout' => 30),
        'https' => array('timeout' => 30),
    ));
    $content = @file_get_contents($url, false, $ctx);
    if ($content === false) {
        return array('ok' => false, 'error' => 'Failed to download modpoll.exe');
    }
    $bytes = @file_put_contents($temp, $content);
    if ($bytes === false) {
        return array('ok' => false, 'error' => 'Failed to save modpoll.exe');
    }
    @rename($temp, $path);
    return array('ok' => file_exists($path), 'path' => $path, 'downloaded' => true);
}

function sse_send($event) {
    echo 'data: ' . json_encode($event) . "\n\n";
    @ob_flush();
    @flush();
}

function list_com_ports() {
    // Try registry query first
    $cmd = 'reg query HKLM\\HARDWARE\\DEVICEMAP\\SERIALCOMM';
    $out = array();
    @exec($cmd, $out, $rc);
    $ports = array();
    if ($rc === 0 && !empty($out)) {
        foreach ($out as $line) {
            if (preg_match('/COM\d+/i', $line, $m)) {
                $ports[] = strtoupper($m[0]);
            }
        }
        $ports = array_values(array_unique($ports));
    }
    if (empty($ports)) {
        $ps = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "[System.IO.Ports.SerialPort]::GetPortNames() | Sort-Object | ForEach-Object { $_ }"';
        $out = array();
        @exec($ps, $out, $rc);
        if ($rc === 0) {
            foreach ($out as $line) {
                $line = trim($line);
                if ($line !== '') { $ports[] = strtoupper($line); }
            }
        }
    }
    natsort($ports);
    return array_values($ports);
}

function normalize_parity($value) {
    $lower = strtolower(trim((string)$value));
    if ($lower === '0' || $lower === 'n' || $lower === 'none') return 'none';
    if ($lower === '1' || $lower === 'o' || $lower === 'odd') return 'odd';
    if ($lower === '2' || $lower === 'e' || $lower === 'even') return 'even';
    return $lower ? $lower : 'none';
}

function build_modpoll_arguments($p) {
    $baud = isset($p['baudrate']) ? (string)$p['baudrate'] : '9600';
    $parity = normalize_parity(isset($p['parity']) ? (string)$p['parity'] : 'none');
    $databits = isset($p['databits']) ? (string)$p['databits'] : '8';
    $stopbits = isset($p['stopbits']) ? (string)$p['stopbits'] : '1';
    $addr = isset($p['address']) ? (string)$p['address'] : '1';
    $ref = isset($p['startref']) ? (string)$p['startref'] : '100';
    $count = isset($p['count']) ? (string)$p['count'] : '1';
    $dtype = isset($p['dtype']) ? (string)$p['dtype'] : '3';
    $tcp = trim(isset($p['tcp']) ? (string)$p['tcp'] : '');
    $com = trim(isset($p['com']) ? (string)$p['com'] : '');

    if ($tcp !== '') {
        $args = array('-m', 'tcp', $tcp, "-b{$baud}", "-p{$parity}", "-d{$databits}", "-s{$stopbits}", "-a{$addr}", "-r{$ref}", "-c{$count}", "-t{$dtype}");
    } else {
        $formatted = strtoupper($com);
        if ($formatted !== '' && stripos($formatted, 'COM') !== 0) { $formatted = 'COM' . $formatted; }
        if (preg_match('/COM(\d+)/i', $formatted, $m)) {
            $n = (int)$m[1];
            if ($n >= 10) { $formatted = '\\\\.' . '\\' . $formatted; }
        }
        $args = array(($formatted !== '' ? $formatted : '[COM_PORT]'), "-b{$baud}", "-p{$parity}", "-a{$addr}");
        if ($databits !== '8') $args[] = "-d{$databits}";
        if ($stopbits !== '1') $args[] = "-s{$stopbits}";
        if ($ref !== '100') $args[] = "-r{$ref}";
        if ($count !== '1') $args[] = "-c{$count}";
        if ($dtype !== '3') $args[] = "-t{$dtype}";
    }
    return $args;
}

function mask_command($args) { return 'modpoll ' . implode(' ', $args); }

function extract_lines($buffer) {
    $buffer = str_replace(array("\r\n", "\r"), "\n", $buffer);
    $lines = explode("\n", $buffer);
    $out = array();
    foreach ($lines as $l) { if ($l !== '') $out[] = $l; }
    return $out;
}

function classify_line($line) {
    $lower = strtolower($line);
    if (strpos($lower, 'reply time-out!') !== false) return array('type' => 'error', 'message' => "Reply time-out!\nEquipment does not respond.");
    if (strpos($lower, 'serial port already open') !== false) return array('type' => 'error', 'message' => 'Serial port already open! Remember to stop plant server in IWMAC escape!');
    if (strpos($lower, 'port or socket open error!') !== false) return array('type' => 'error', 'message' => 'Port or socket open error! Remember to stop plant server in IWMAC escape!');
    if (strpos($lower, 'checksum error') !== false) return array('type' => 'error', 'message' => 'Checksum Error Detected!');
    if (strpos($lower, 'illegal function exception response!') !== false) return array('type' => 'info', 'message' => 'Illegal Function Exception Response!');
    if (strpos($lower, 'illegal data address exception response!') !== false) return array('type' => 'info', 'message' => 'Illegal Data Address Exception Response!');
    if (strpos($lower, 'illegal data value exception response!') !== false) return array('type' => 'info', 'message' => 'Illegal Data Value Exception Response!');
    if (strpos($line, 'modpoll - FieldTalk') !== false) return array('type' => 'skip', 'message' => $line);
    if (strpos($line, 'Copyright') !== false) return array('type' => 'skip', 'message' => $line);
    if (strpos($lower, 'polling slave (ctrl-c to stop) ...') !== false) return array('type' => 'skip', 'message' => $line);
    return array('type' => 'normal', 'message' => $line);
}

// ---------------------------- ROUTER ---------------------------------
$action = isset($_GET['action']) ? $_GET['action'] : '';
if ($action !== '') {
    if ($action === 'ensure_modpoll') {
        $res = ensure_modpoll_exists($MODPOLL_PATH, $MODPOLL_DOWNLOAD_URL);
        respond_json($res, (!empty($res['ok']) ? 200 : 500));
        exit;
    }
    if ($action === 'list_ports') { respond_json(array('ports' => list_com_ports())); exit; }
    if ($action === 'build') {
        $params = array_merge($_GET, $_POST);
        $args = build_modpoll_arguments($params);
        respond_json(array('preview' => mask_command($args), 'args' => $args));
        exit;
    }
    if ($action === 'run') {
        @set_time_limit(0);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        $chk = ensure_modpoll_exists($MODPOLL_PATH, $MODPOLL_DOWNLOAD_URL);
        if (empty($chk['ok'])) { sse_send(array('type'=>'error','message'=>'modpoll.exe not found and download failed')); exit; }
        $customCmd = isset($_GET['custom']) ? trim((string)$_GET['custom']) : '';
        if ($customCmd !== '') {
            // Sanitize: strip any leading quoted modpoll.exe or 'modpoll' word
            $cc = preg_replace('/^\s*"[^"]*modpoll\.exe"\s+/i', '', $customCmd);
            $cc = preg_replace('/^\s*modpoll\s+/i', '', $cc);
            $cmd = '"' . $MODPOLL_PATH . '" ' . $cc;
            sse_send(array('type'=>'info','message'=>'Running: modpoll ' . $cc));
        } else {
            $args = build_modpoll_arguments($_GET);
            $cmd = '"' . $MODPOLL_PATH . '" ' . implode(' ', $args);
            sse_send(array('type'=>'info','message'=>'Running: ' . mask_command($args)));
        }

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) { sse_send(array('type'=>'error','message'=>'Failed to start modpoll')); exit; }
        $status = proc_get_status($proc);
        $pid = !empty($status['pid']) ? $status['pid'] : '';
        if ($pid) { @file_put_contents($PID_FILE, (string)$pid); }
        stream_set_blocking($pipes[1], 0);
        stream_set_blocking($pipes[2], 0);
        fclose($pipes[0]);

        $fatalStop = false;
        while (true) {
            $status = proc_get_status($proc);
            $live = !empty($status['running']);

            $chunkOut = @fread($pipes[1], 8192);
            if ($chunkOut !== false && $chunkOut !== '') {
                $lines = extract_lines($chunkOut);
                foreach ($lines as $line) {
                    $cls = classify_line($line);
                    if ($cls['type'] !== 'skip') {
                        sse_send($cls);
                        if ($cls['type'] === 'error' && strpos(strtolower($cls['message']), 'port or socket open error!') !== false) {
                            @proc_terminate($proc);
                            $fatalStop = true;
                            break;
                        }
                    }
                }
            }
            $chunkErr = @fread($pipes[2], 8192);
            if ($chunkErr !== false && $chunkErr !== '') {
                $lines = extract_lines($chunkErr);
                foreach ($lines as $line) {
                    $cls = classify_line($line);
                    if ($cls['type'] === 'skip') continue;
                    if ($cls['type'] === 'normal') $cls['type'] = 'error';
                    sse_send($cls);
                    if ($cls['type'] === 'error' && strpos(strtolower($cls['message']), 'port or socket open error!') !== false) {
                        @proc_terminate($proc);
                        $fatalStop = true;
                        break;
                    }
                }
            }
            if ($fatalStop || !$live) { break; }
            usleep(50000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        @proc_close($proc);
        @unlink($PID_FILE);
        sse_send(array('type'=>'info','message'=>'Polling finished.'));
        exit;
    }
    if ($action === 'stop') {
        $pid = @file_exists($PID_FILE) ? trim((string)@file_get_contents($PID_FILE)) : '';
        if ($pid !== '') { @exec('taskkill /PID ' . escapeshellarg($pid) . ' /T /F'); @unlink($PID_FILE); respond_json(array('ok'=>true,'message'=>'Stopped.')); }
        else { respond_json(array('ok'=>true,'message'=>'No running process.')); }
        exit;
    }
    if ($action === 'units') {
        $query =
            "SELECT u.unit_id, u.unit_name, u.driver_type, u.driver_addr, u.regulator_type, " .
            "COALESCE(com_port_setting.value, '') as com_port, " .
            "COALESCE(REPLACE(REPLACE(ip_setting.value, CHAR(13), ''), CHAR(10), ''), '') as ip_address, " .
            "COALESCE(mb_mode_setting.value, '0') as mb_mode, " .
            "COALESCE(REPLACE(REPLACE(mb_tcp_servers_setting.value, CHAR(13), ''), CHAR(10), ''), '') as mb_tcp_servers, " .
            "COALESCE(baudrate_setting.value, '') as baudrate, " .
            "COALESCE(parity_setting.value, '') as parity " .
            "FROM iw_sys_plant_units u " .
            "LEFT JOIN iw_sys_plant_settings com_port_setting ON u.driver_type = com_port_setting.owner AND com_port_setting.setting = 'comm_port' " .
            "LEFT JOIN iw_sys_plant_settings ip_setting ON u.driver_type = ip_setting.owner AND " .
            "   (ip_setting.value REGEXP '^[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}$' OR " .
            "    ip_setting.value REGEXP 'https?://[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}' OR " .
            "    ip_setting.value REGEXP '[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}') " .
            "LEFT JOIN iw_sys_plant_settings mb_mode_setting ON u.driver_type = mb_mode_setting.owner AND mb_mode_setting.setting = 'mb_mode' " .
            "LEFT JOIN iw_sys_plant_settings mb_tcp_servers_setting ON u.driver_type = mb_tcp_servers_setting.owner AND mb_tcp_servers_setting.setting = 'mb_tcp_servers' " .
            "LEFT JOIN iw_sys_plant_settings baudrate_setting ON u.driver_type = baudrate_setting.owner AND baudrate_setting.setting = 'comm_baudrate' " .
            "LEFT JOIN iw_sys_plant_settings parity_setting ON u.driver_type = parity_setting.owner AND parity_setting.setting = 'comm_parity' " .
            "ORDER BY u.unit_id";

        $rows = array();
        if (class_exists('mysqli')) {
            $users = array(array($GLOBALS['DB_USER_PRIMARY'], $GLOBALS['DB_PASS_PRIMARY']), array($GLOBALS['DB_USER_FALLBACK'], $GLOBALS['DB_PASS_FALLBACK']));
            $err = '';
            foreach ($users as $up) {
                $u = $up[0]; $p = $up[1];
                $mysqli = @new mysqli($GLOBALS['DB_HOST'], $u, $p, $GLOBALS['DB_NAME']);
                if ($mysqli && !$mysqli->connect_errno) {
                    @$mysqli->set_charset('utf8mb4');
                    $res = @$mysqli->query($query);
                    if ($res) {
                        while ($r = $res->fetch_row()) {
                            $unit_id = isset($r[0]) ? $r[0] : '';
                            $unit_name = isset($r[1]) ? $r[1] : '';
                            $driver_type = isset($r[2]) ? $r[2] : '';
                            $driver_addr = isset($r[3]) ? $r[3] : '';
                            $regulator_type = isset($r[4]) ? $r[4] : '';
                            $com_port_value = isset($r[5]) ? $r[5] : '';
                            $full_ip_value = isset($r[6]) ? $r[6] : '';
                            $mb_mode = isset($r[7]) ? $r[7] : '0';
                            $mb_tcp_servers = isset($r[8]) ? $r[8] : '';
                            $baudrate_value = isset($r[9]) ? $r[9] : '';
                            $parity_value_raw = isset($r[10]) ? $r[10] : '';
                            $parity_value = normalize_parity((string)$parity_value_raw);

                            $clean_ip = '';
                            if (preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', (string)$full_ip_value, $m2)) {
                                $clean_ip = ($m2[0] === '127.0.0.1') ? '' : $m2[0];
                            }
                            if ($mb_mode === '2') {
                                $com_port_value = '';
                                if ($clean_ip === '' && $mb_tcp_servers) {
                                    $parts = explode(';', (string)$mb_tcp_servers);
                                    if (count($parts) >= 2) {
                                        $ip = trim($parts[1]);
                                        if (preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $ip)) { $clean_ip = $ip; }
                                    }
                                }
                            }
                            if ($driver_type === 'AK2' && $clean_ip === '') { continue; }
                            $rows[] = array($unit_id, $unit_name, $driver_type, $driver_addr, $regulator_type, $clean_ip, $com_port_value, $baudrate_value, $parity_value);
                        }
                        $res->free();
                        $mysqli->close();
                        break;
                    } else { $err = $mysqli->error; $mysqli->close(); }
                } else { $err = $mysqli ? $mysqli->connect_error : 'mysqli connect error'; }
            }
            if (empty($rows) && $err !== '') { respond_json(array('ok'=>false,'error'=>$err), 500); }
            else { respond_json(array('ok'=>true,'rows'=>$rows)); }
        } else {
            respond_json(array('ok'=>false,'error'=>'mysqli not available'), 500);
        }
        exit;
    }
    respond_json(array('error'=>'Unknown action'), 400);
    exit;
}

// ---------------------------- UI (Steam-styled) -----------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Modpoll Web UI</title>
    <style>
        /* Global sizing and overflow control to avoid horizontal scroll */
        *, *::before, *::after{ box-sizing: border-box; }
        :root {
            /* Base / Background */
            --steam-dark-1: #171a21;
            --steam-dark-2: #1b2838;
            --steam-dark-3: #2a475e;
            --steam-dark-4: #232730;
            --steam-dark-5: #292e37;

            /* Accent / Highlight / Text */
            --steam-accent-blue: #66c0f4;
            --steam-grey: #adadad;

            /* Light / Neutral */
            --steam-light: #c7d5e0;

            /* Brand / Logo */
            --steam-brand-blue: #00adee;
            --steam-black: #000000;

            /* Functional / Status */
            --success: #5c7e10;   /* discounts and offers */
            --warning: #e7a100;   /* time-limited */
            --error:   #e22121;   /* urgent/error */
            
            /* Legacy fallback used by status dots */
            --green:#5c7e10; --red:#e22121; --amber:#e7a100;
        }
        html,body{ height:100%; overflow-x:hidden; overflow-y:auto; }
        body{
            margin:0; background:linear-gradient(180deg, var(--steam-dark-2) 0%, var(--steam-dark-1) 60%); color:var(--steam-light);
            font-family: Arial, "Segoe UI", Helvetica, sans-serif; font-size:16px; line-height:1.45;
        }
        a{ color: var(--steam-accent-blue); text-decoration:none; transition: color .2s ease; }
        a:hover{ text-decoration:underline; filter: brightness(1.1); }
        a:visited{ filter: brightness(.95); }
        .topbar{ background:linear-gradient(180deg,var(--steam-dark-2) 0%, var(--steam-dark-1) 100%); border-bottom:1px solid var(--steam-dark-5); padding:14px 20px; display:flex; gap:16px; align-items:center; font-size:14px; }
        .brand{ font-weight:700; color:var(--steam-accent-blue); }
        .container{ padding:16px 20px; max-width: none; width: 100%; margin: 0; }
        .grid{ display:grid; grid-template-columns:260px minmax(0, 1fr); gap:16px; width:100%; align-items:stretch; }
        .card{ background:var(--steam-dark-3); border:1px solid var(--steam-dark-5); border-radius:4px; box-shadow: 0 2px 6px rgba(0,0,0,0.3); display:flex; flex-direction:column; min-height:0; }
        .card h3{ margin:0; padding:12px 14px; border-bottom:1px solid var(--steam-dark-5); font-size:18px; color:var(--steam-accent-blue); font-weight:700; text-transform: uppercase; letter-spacing: .5px; }
        .card .content{ padding:12px 16px; display:flex; flex-direction:column; gap:8px; flex:1; min-height:0; }
        .row{ display:flex; gap:10px; align-items:center; margin-bottom:10px; }
        label{ min-width:130px; color: var(--steam-grey); }
        input[type=text], select{ width:100%; background:var(--steam-dark-4); color:var(--steam-light); border:1px solid var(--steam-dark-5); border-radius:4px; padding:8px 10px; box-sizing:border-box; }
        input[type=text]:focus, select:focus{ outline:none; border-color: var(--steam-accent-blue); box-shadow: 0 0 0 2px rgba(102,192,244,0.15); }
        ::-webkit-input-placeholder{ color: var(--steam-grey); }
        ::placeholder{ color: var(--steam-grey); }
        input[readonly]{ opacity:.9; }
        .btn{ border:0; cursor:pointer; border-radius:4px; padding:8px 14px; font-weight:600; color:var(--steam-light); background:var(--steam-dark-3); transition:background .2s,color .2s, box-shadow .2s, transform .1s; text-transform: uppercase; letter-spacing: .5px; text-align:center; }
        .btn:hover{ background:var(--steam-accent-blue); color:var(--steam-black); box-shadow: 0 0 0 2px rgba(102,192,244,0.15) inset; }
        .btn:active{ filter: brightness(0.92); transform: translateY(1px); }
        .btn:disabled{ background: var(--steam-dark-5); color: var(--steam-grey); cursor:not-allowed; opacity: .7; }
        .btn.primary{ background: var(--steam-accent-blue); color: var(--steam-black); }
        .btn.primary:hover{ filter: brightness(1.05); }
        .btn.secondary{ background:#292e37; color:var(--steam-light); }
        .btn.secondary:hover{ background: #2a475e; color: var(--steam-light); }
        .btn.danger{ background:#c33; color:#fff; }
        .btn.danger:hover{ background:#a92a2a; }
        /* Stop button visual states */
        .btn.stop-active{ background:#a92a2a; color:#fff; }
        .btn.stop-idle{ background:#FCA5A5; color:#fff; }
        .equip-search{ display:flex; gap:8px; margin-bottom:10px; }
        .equip-list{ height:auto; overflow:auto; background:var(--steam-dark-4); border:1px solid var(--steam-dark-5); border-radius:4px; }
        .equipment-card{ position: sticky; top: 16px; align-self: start; }
        .equip-item{ padding:8px 10px; line-height:1.25; border-bottom:1px solid rgba(255,255,255,0.06); cursor:pointer; color:var(--steam-light); }
        .equip-item:hover{ background:var(--steam-dark-2); }
        .equip-item.active{ background:var(--steam-dark-2); color:#fff; }
        .split{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .status{ width:16px; height:16px; border-radius:50%; display:inline-block; border:2px solid rgba(255,255,255,0.15); margin-left:6px; background:var(--steam-grey); }
        .status.green{ background:var(--success); } .status.yellow{ background:var(--warning); } .status.red{ background:var(--error); }
        .terminal{ border:3px solid var(--steam-dark-5); border-radius:6px; overflow:hidden; background: var(--steam-dark-2); box-shadow: 0 2px 10px rgba(0,0,0,.35); height:420px; display:flex; flex-direction:column; }
        .terminal-head{ background: var(--steam-dark-5); color: var(--steam-accent-blue); font-weight:800; letter-spacing:.8px; padding:8px 12px; font-size:12px; text-transform: uppercase; border-bottom:1px solid #232730; display:flex; align-items:center; justify-content:space-between; }
        .term-controls{ display:flex; gap:8px; align-items:center; }
        .terminal-cmd{ display:flex; gap:8px; align-items:center; padding:8px 12px; background: var(--steam-dark-4); border-bottom:1px solid var(--steam-dark-5); }
        .terminal-cmd label{ color: var(--steam-grey); min-width:auto; }
        .terminal-cmd input{ flex:1; }
        .log{ background:var(--steam-dark-2); color:var(--steam-light); border-top:1px solid var(--steam-dark-5); border-radius:0; font-family:Consolas,monospace; font-size:12px; padding:12px; height:auto; min-height:0; flex:1; overflow:auto; overflow-y:auto; white-space:pre-wrap; word-break: break-word; overflow-wrap: anywhere; }
        .toolbar{ display:flex; gap:8px; align-items:center; padding:10px 14px; border-bottom:1px solid var(--steam-dark-5); background:var(--steam-dark-4); border-radius:4px 4px 0 0; }
        .footer{ margin-top:14px; color:var(--steam-grey); font-size:12px; display:flex; justify-content:space-between; }
        table{ width:100%; border-collapse:collapse; table-layout: fixed; }
        th,td{ text-align:left; padding:8px 12px; border-bottom:1px solid #292e37; font-size:12px; }
        tbody tr:nth-child(odd){ background: #232730; }
        tbody tr:nth-child(even){ background: #1b2838; }
        tbody tr:hover{ background:#2a475e; }

        /* Dark scrollbars (WebKit-based browsers) */
        ::-webkit-scrollbar{ width: 10px; height: 10px; }
        ::-webkit-scrollbar-track{ background: #171a21; }
        ::-webkit-scrollbar-thumb{ background: #2a475e; border-radius: 10px; border: 2px solid #171a21; }
        ::-webkit-scrollbar-thumb:hover{ background: #66c0f4; }

        /* Alerts / Badges */
        .alert{ padding:10px 12px; border-radius:4px; margin:8px 0; font-weight:600; }
        .alert.success{ background: rgba(92,126,16,.2); color: var(--steam-light); border:1px solid var(--success); }
        .alert.warning{ background: rgba(231,161,0,.18); color: var(--steam-light); border:1px solid var(--warning); }
        .alert.error{ background: rgba(226,33,33,.18); color: var(--steam-light); border:1px solid var(--error); }
        .badge{ display:inline-block; padding:2px 6px; border-radius:3px; font-size:12px; font-weight:700; }
        .badge.discount{ background: var(--success); color: #fff; }
        .price-old{ color: var(--steam-grey); text-decoration: line-through; }
        .price-new{ color: var(--steam-light); font-weight:700; }

        /* Icon baseline */
        .icon{ color: var(--steam-light); transition: color .2s ease; }
        .icon:hover{ color: var(--steam-accent-blue); }

        /* Game tiles */
        .tile-grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); grid-column-gap:20px; grid-row-gap:16px; }
        .tile{ position:relative; border:1px solid #232730; border-radius:4px; overflow:hidden; background:#0b0f16; box-shadow: 0 2px 6px rgba(0,0,0,.25); transition: filter .2s ease; }
        .tile:hover{ filter: brightness(1.05); }
        .tile img{ width:100%; height:140px; object-fit:cover; display:block; }
        .tile-overlay{ position:absolute; inset:auto 0 0 0; height:55%; background: linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,.75) 100%); display:flex; align-items:flex-end; padding:10px; }
        .tile-title{ color:#c7d5e0; font-weight:700; font-size:14px; }
        .tile-actions{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; opacity:0; transition: opacity .2s ease; }
        .tile:hover .tile-actions{ opacity:1; }
        .tile .discount{ position:absolute; top:8px; left:8px; }

        /* Spinner */
        .spinner{ width:18px; height:18px; border:2px solid #adadad; border-top-color:#66c0f4; border-radius:50%; animation: spin 0.75s linear infinite; display:inline-block; }
        @keyframes spin{ to { transform: rotate(360deg); } }

        /* Dropdowns and menus */
        .dropdown{ position: relative; }
        .dropdown-menu{ position:absolute; top:100%; left:0; min-width:180px; background:#1b2838; border:1px solid #232730; border-radius:4px; box-shadow:0 2px 8px rgba(0,0,0,.35); padding:6px 0; z-index:1000; display:none; }
        .dropdown.open .dropdown-menu{ display:block; }
        .dropdown-item{ padding:8px 12px; color:var(--steam-light); cursor:pointer; }
        .dropdown-item:hover{ background:#2a475e; }

        /* Modal */
        .modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.7); display:none; }
        .modal{ position:fixed; inset:0; display:none; align-items:center; justify-content:center; }
        .modal.open, .modal-backdrop.open{ display:flex; }
        .modal-box{ background:#1b2838; border:1px solid #232730; border-radius:4px; width:560px; max-width:90vw; box-shadow:0 6px 18px rgba(0,0,0,.45); }
        .modal-box .modal-header{ padding:12px 16px; border-bottom:1px solid #232730; color:var(--steam-accent-blue); font-weight:700; }
        .modal-box .modal-body{ padding:12px 16px; color:var(--steam-light); }
        .modal-close{ color:var(--steam-grey); cursor:pointer; }
        .modal-close:hover{ color:var(--steam-accent-blue); }

        /* Player */
        .player{ background:#0b0f16; padding:10px; border:1px solid #232730; border-radius:4px; }
        .progress{ background:#292e37; height:6px; border-radius:3px; overflow:hidden; }
        .progress .bar{ height:100%; width:0; background:#66c0f4; }
        .control{ color:#c7d5e0; cursor:pointer; }
        .control:hover{ color:#66c0f4; }

        /* Units table shows full content (no internal scroll) */
        .units-scroll{ max-height:none; overflow:visible; border:1px solid var(--steam-dark-5); border-radius:6px; }
    </style>
</head>
<body>
<div class="topbar"><div class="brand">Modpoll Web UI</div></div>
<div class="container">
  <div class="grid">
    <div class="card equipment-card">
      <h3>Equipment</h3>
      <div class="content">
        <div class="equip-search">
          <input id="equipSearch" type="text" placeholder="Search..." />
          <button class="btn secondary" id="clearSearch">Clear</button>
        </div>
        <div id="equipList" class="equip-list"></div>
      </div>
    </div>
    <div class="card">
      <div class="toolbar">
        <div style="font-weight:700;">Settings</div>
      </div>
      <div class="content">
        <div class="split">
          <div>
            <div class="row"><label for="com">COM Port</label><select id="com"></select><button id="btnRefreshPorts" class="btn secondary">Refresh</button></div>
            <div class="row"><label for="baud">Baudrate (-b)</label><select id="baud"><option>2400</option><option>4800</option><option selected>9600</option><option>19200</option><option>38400</option><option>57600</option><option>115200</option></select></div>
            <div class="row"><label for="parity">Parity (-p)</label><select id="parity"><option selected>none</option><option>even</option><option>odd</option></select></div>
            <div class="row"><label for="addr">Address (-a)</label><input id="addr" type="text" value="1" /></div>
          </div>
          <div>
            <div class="row"><label for="databits">Data Bits (-d)</label><select id="databits"><option>7</option><option selected>8</option></select></div>
            <div class="row"><label for="stopbits">Stop Bits (-s)</label><select id="stopbits"><option selected>1</option><option>2</option></select></div>
            <div class="row"><label for="startref">Start Ref (-r)</label><input id="startref" type="text" value="100" /></div>
            <div class="row"><label for="count">Registers (-c)</label><input id="count" type="text" value="1" /></div>
            <div class="row"><label for="dtype">Reg Type (-t)</label><input id="dtype" type="text" value="3" /></div>
            <div class="row"><label for="tcp">Modbus TCP<br>(-m tcp)</label><input id="tcp" type="text" placeholder="IP:[port]" /></div>
          </div>
        </div>
      </div>
      <div class="terminal">
        <div class="terminal-head"><span>Terminal</span>
          <div class="term-controls">
            <button id="btnStart" class="btn">Start Polling</button>
            <button id="btnStop" class="btn danger stop-idle">Stop</button>
            <span style="color:#8f98a0;">Status</span><span id="statusDot" class="status"></span>
          </div>
        </div>
        <div class="terminal-cmd"><label for="preview">Command</label><input id="preview" type="text" /></div>
        <div class="log" id="log"></div>
      </div>
    </div>
  </div>

  <div class="card units-card" style="margin-top:16px; min-height:0;">
    <h3>Units</h3>
    <div class="content">
      <div class="row"><button id="btnGetUnits" class="btn secondary">Get units data</button><button id="btnToggleBaud" class="btn secondary">Modbus-supported units</button><span style="color:#8f98a0;">Loads from local MySQL if available.</span></div>
      <div class="units-scroll">
        <table id="unitsTable"><thead><tr><th>Unit ID</th><th>Unit Name</th><th>Driver Type</th><th>Driver Address</th><th>Regulator Type</th><th>IP Address</th><th>COM Port</th><th>Baudrate</th><th>Parity</th></tr></thead><tbody></tbody></table>
      </div>
    </div>
  </div>

  <div class="footer">
    <div><a href="https://iwmac.zendesk.com/hc/en-gb/articles/13020280416796-Installasjon-Modpoll-Guide" target="_blank" style="color:#66c0f4; text-decoration:none;">Modpoll guide</a></div>
    <div>©TKH</div>
  </div>
</div>

<script>
  var EQUIPMENT = {"ADAM":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"AERMEC":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"AKCC250":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"AKCC350":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"AKCC55":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"AKCC550A":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"AKPC420":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"ANYBUS":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"none"},"ATLANTIUM":{"baudrate":"115200","stop_bits":"1","data_bits":"8","parity":"none"},"AWD3":{"baudrate":"19200","stop_bits":"2","data_bits":"8","parity":"none"},"BELIMO":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"CAREL":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"none"},"CIAT2":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"CIRCUTOR":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"even"},"CLIMAVENTA":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"CLIVET":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"CORRIGO":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"CORRIGO34":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"CVM10":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"CVM96":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"CVMC":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"DAIKIN":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"none"},"DIXELL":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"DUPLINE":{"baudrate":"115200","stop_bits":"1","data_bits":"8","parity":"none"},"EDMK":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"Carlo Gavazzi EM100":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM21":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM210":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM23":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM24":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM24TCP":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"Carlo Gavazzi EM26":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM270":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM330":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM4":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"Carlo Gavazzi EM540":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"EW":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"FLAKTWOODS":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"FLEXIT":{"baudrate":"9600","stop_bits":"2","data_bits":"8","parity":"none"},"GREENCOOL":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"GRFOS":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"HECU":{"baudrate":"19200","stop_bits":"2","data_bits":"8","parity":"none"},"IEM3250":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"INEPRO":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"even"},"INTESIS":{"baudrate":"9600","stop_bits":"2","data_bits":"8","parity":"none"},"IR33PLUS":{"baudrate":"19200","stop_bits":"2","data_bits":"8","parity":"none"},"IVPRODUKT":{"baudrate":"9600","stop_bits":"2","data_bits":"8","parity":"none"},"IWT":{"baudrate":"57600","stop_bits":"2","data_bits":"8","parity":"none"},"KAMSTRUP":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"LANDIS":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"LDS":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"LEMMENS":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"LIEBHERR":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"MKD":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"MODBUS":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"NEMO96":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"none"},"NETAVENT":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"NOVAGG":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"OJEXHAUST":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"PIIGAB":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"PMGOLD":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"POWERTAG":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"PR100T":{"baudrate":"19200","stop_bits":"2","data_bits":"8","parity":"none"},"QALCOSONIC":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"even"},"REGIN":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"REGINRCF":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"even"},"SCHNEIDER":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"SLV AHT":{"baudrate":"19200","stop_bits":"1","data_bits":"8","parity":"even"},"SOLARLOG":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"SWEGON":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"TROX":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"UH50":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"UNISAB3":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"VENT":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"VIESSMANN":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"WM14":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"},"WTRANS":{"baudrate":"9600","stop_bits":"1","data_bits":"8","parity":"none"}};
  function el(id){ return document.getElementById(id); }
  var statusDot = el('statusDot'); var es = null; var cmdDirty = false;
  function normalizeCom(val){
    var s = (val||'').toString().trim();
    if(!s) return s;
    s = s.replace(/^\\\\\.\\/,'');
    s = s.toUpperCase();
    if(/^COM\d+$/.test(s)) return s;
    if(/^\d+$/.test(s)) return 'COM'+s;
    return s;
  }
  function setStatus(c){ statusDot.className = 'status' + (c ? ' ' + c : ''); }
  function logLine(t,ty){ var log=el('log'); var d=document.createElement('div'); if(ty==='error'){d.style.color='#f87171';} else if(ty==='info'){d.style.color='#34d399';} d.textContent=t; log.appendChild(d); log.scrollTop=log.scrollHeight; }
  function collectParams(){ return { com:el('com').value, baudrate:el('baud').value, parity:el('parity').value, address:el('addr').value.replace(/^\s+|\s+$/g,''), databits:el('databits').value, stopbits:el('stopbits').value, startref:el('startref').value.replace(/^\s+|\s+$/g,''), count:el('count').value.replace(/^\s+|\s+$/g,''), dtype:el('dtype').value.replace(/^\s+|\s+$/g,''), tcp:el('tcp').value.replace(/^\s+|\s+$/g,'') }; }
  function buildPreview(){ if(cmdDirty){ return Promise.resolve(); } var p=collectParams(); var data=new URLSearchParams(p); return fetch('?action=build',{method:'POST',body:data}).then(function(r){return r.json();}).then(function(j){ if(!cmdDirty){ el('preview').value=j.preview||''; } }); }
  function refreshPorts(){ return fetch('?action=list_ports').then(function(r){return r.json();}).then(function(j){ var ports=j.ports||[]; var sel=el('com'); sel.innerHTML=''; var blank=document.createElement('option'); blank.value=''; blank.textContent=''; sel.appendChild(blank); ports.forEach(function(p){ var o=document.createElement('option'); o.value=p; o.textContent=p; sel.appendChild(o); }); annotateComDropdown(); }); }
  function start(customText){ if(es){ es.close(); es=null; } el('log').innerHTML=''; setStatus(''); var url=''; if(customText && customText.replace(/^\s+|\s+$/g,'')!==''){ url='?action=run&custom='+encodeURIComponent(customText); } else { var p=collectParams(); var qs=new URLSearchParams(p).toString(); url='?action=run&'+qs; } es=new EventSource(url); var stopBtn = document.getElementById('btnStop'); if(stopBtn){ stopBtn.classList.remove('stop-idle'); stopBtn.classList.add('stop-active'); } es.onmessage=function(e){ var d=JSON.parse(e.data); if(d.type==='error'){ setStatus('red'); if(d.message && /port or socket open error!/i.test(d.message)){ if(es){ es.close(); es=null; } var sb=document.getElementById('btnStop'); if(sb){ sb.classList.remove('stop-active'); sb.classList.add('stop-idle'); } } } if(d.type==='info'){ setStatus('green'); if(d.message && /Polling finished\./.test(d.message)){ var sb2=document.getElementById('btnStop'); if(sb2){ sb2.classList.remove('stop-active'); sb2.classList.add('stop-idle'); } } } if(/\[(\d+)\]:/.test(d.message)){ setStatus('green'); } logLine(d.message,d.type); }; es.onerror=function(){ setStatus(''); var sb=document.getElementById('btnStop'); if(sb){ sb.classList.remove('stop-active'); sb.classList.add('stop-idle'); } }; }
  function stop(){ if(es){ es.close(); es=null; } fetch('?action=stop'); var sb=document.getElementById('btnStop'); if(sb){ sb.classList.remove('stop-active'); sb.classList.add('stop-idle'); } }
  function populateEquipment(){ var list=el('equipList'); list.innerHTML=''; var keys=Object.keys(EQUIPMENT).sort(); keys.forEach(function(k){ var div=document.createElement('div'); div.className='equip-item'; div.textContent=k; div.onclick=function(){ [].forEach.call(list.children,function(c){c.classList.remove('active');}); div.classList.add('active'); var s=EQUIPMENT[k]; el('baud').value=s.baudrate||'9600'; el('parity').value=s.parity||'none'; el('stopbits').value=s.stop_bits||'1'; el('databits').value=s.data_bits||'8'; cmdDirty=false; buildPreview(); }; list.appendChild(div); }); }
  function sizeEquipListToTerminal(){
    try{
      var list=document.querySelector('.equip-list');
      var term=document.querySelector('.terminal');
      if(!list||!term){return;}
      var lb=list.getBoundingClientRect();
      var tb=term.getBoundingClientRect();
      var available = tb.top - lb.top - 12; // space until terminal
      var target = Math.min(640, Math.max(260, available)); // clamp between 260px and 640px
      list.style.maxHeight = target + 'px';
    }catch(e){}
  }
  function filterEquipment(){ var q=el('equipSearch').value.toLowerCase().replace(/^\s+|\s+$/g,''); var items=[].slice.call(el('equipList').children); var shown=0; items.forEach(function(it){ var ok=it.textContent.toLowerCase().indexOf(q)!==-1; it.style.display=ok?'':'none'; if(ok) shown++; }); if(shown===1){ var it=items.find(function(x){return x.style.display!== 'none';}); if(it) it.click(); } }
  var cachedUnits = [];
  var comLabelMap = {};

  window.unitsSizeBoost = 1;

  function annotateComDropdown(){
    try{
      var sel = el('com');
      if(!sel) return;
      for(var i=0;i<sel.options.length;i++){
        var opt = sel.options[i];
        var val = normalizeCom(opt.value||'');
        opt.value = val;
        if(val===''){ opt.textContent = ''; continue; }
        var drv = comLabelMap[val];
        opt.textContent = drv ? (val + ' - ' + drv) : val;
      }
    }catch(e){}
  }
  var hideNoBaud = false;
  function updateBaudButton(){ var b=document.getElementById('btnToggleBaud'); if(b){ b.textContent = hideNoBaud ? 'Show all units' : 'Modbus-supported units'; } }
  function renderUnits(rows){
    var tbody = document.querySelector('#unitsTable tbody');
    tbody.innerHTML = '';
    var list = rows || [];
    if(hideNoBaud){ list = list.filter(function(r){ return String((r[7]||'')+'').trim() !== ''; }); }
    if(list.length === 0){ var tr=document.createElement('tr'); var td=document.createElement('td'); td.colSpan=9; td.textContent='No units'; tr.appendChild(td); tbody.appendChild(tr); return; }
    list.forEach(function(r){
      var tr=document.createElement('tr');
      r.forEach(function(v){ var td=document.createElement('td'); td.textContent=v||''; tr.appendChild(td); });
      tr.style.cursor = 'pointer';
      tr.addEventListener('click', function(){
        function ensureSelectValue(id, value){
          var sel = el(id);
          if(!sel) return;
          if(sel.tagName && sel.tagName.toLowerCase() === 'select'){
            var found=false; for(var i=0;i<sel.options.length;i++){ if(sel.options[i].value===value){ found=true; break; } }
            if(!found){ var o=document.createElement('option'); o.value=value; o.textContent=value; sel.appendChild(o); }
            sel.value = value;
          } else { sel.value = value; }
        }
        var driverAddr = r[3] || '';
        var addr = '';
        var parts = String(driverAddr).split('_');
        if(parts.length===2 && /^[0-9]+$/.test(parts[1])){ addr = parts[1]; }
        else if(/^[0-9]+$/.test(String(driverAddr))){ addr = String(driverAddr); }
        else { var m = String(driverAddr).match(/(\d+)$/); if(m){ addr = m[1]; } }
        var com = (r[6]||'').toString().trim();
        var baud = (r[7]||'').toString().trim();
        var parity = (r[8]||'').toString().trim().toLowerCase();
        if(parity==='e'){ parity='even'; } else if(parity==='o'){ parity='odd'; } else if(parity==='n' || parity===''){ parity='none'; }
        var ip = (r[5]||'').toString().trim();
        if(com){ ensureSelectValue('com', com); el('tcp').value=''; }
        else if(ip){ el('tcp').value = ip; }
        if(baud){ ensureSelectValue('baud', baud); }
        if(parity && (parity==='none'||parity==='even'||parity==='odd')){ ensureSelectValue('parity', parity); }
        if(addr){ el('addr').value = addr; }
        cmdDirty = false;
        buildPreview();
      });
      tbody.appendChild(tr);
    });
    updateBaudButton();
  }
  function loadUnits(){
    var tbody=document.querySelector('#unitsTable tbody'); tbody.innerHTML='';
    fetch('?action=units').then(function(r){return r.json();}).then(function(j){
      if(!j.ok){ var tr=document.createElement('tr'); var td=document.createElement('td'); td.colSpan=9; td.textContent='Failed: '+(j.error||'unknown'); tr.appendChild(td); tbody.appendChild(tr); return; }
      cachedUnits = j.rows || [];
      // Default view: Modbus-supported units only
      hideNoBaud = true;
      updateBaudButton();
      renderUnits(cachedUnits);
      // Build COM -> driver label map and annotate COM dropdown labels
      try{
        var map = {};
        (cachedUnits||[]).forEach(function(r){
          var rawCom=(r[6]||'').toString().trim();
          var com = normalizeCom(rawCom);
          if(!com) return;
          var owner=(r[2]||'').toString().trim();
          if(!map[com]){ map[com]=owner; }
          // If multiple owners share a COM, prefer 'SLV'
          if(/SLV/i.test(owner)) map[com] = 'SLV';
        });
        // Reduce owner to label (prefer exact 'SLV', else first token upper)
        Object.keys(map).forEach(function(k){ var v=map[k]||''; if(/SLV/i.test(v)) map[k]='SLV'; else { var t=v.split(/\s+/)[0]||v; map[k]=(t||'').toUpperCase(); } });
        comLabelMap = map;
        annotateComDropdown();
      }catch(e){}
      // Boost units area by 30% on first expansion
      window.unitsSizeBoost = 1.3;
      setTimeout(sizeUnitsScroll, 0);
    });
  }
  document.getElementById('btnRefreshPorts').onclick=function(){ refreshPorts().then(buildPreview); };
  document.getElementById('btnStart').onclick=function(){ var custom = cmdDirty ? el('preview').value : ''; if(custom && custom.replace(/^\s+|\s+$/g,'')!==''){ var cc = custom.replace(/^\s*"[^"]*modpoll\.exe"\s+/i,'').replace(/^\s*modpoll\s+/i,''); start(cc); } else { buildPreview().then(function(){ start(''); }); } };
  document.getElementById('btnStop').onclick=function(){ stop(); };
  document.getElementById('btnGetUnits').onclick=function(){ loadUnits(); };
  document.getElementById('btnToggleBaud').onclick=function(){ hideNoBaud = !hideNoBaud; updateBaudButton(); renderUnits(cachedUnits); setTimeout(sizeUnitsScroll, 0); };
  document.getElementById('clearSearch').onclick=function(){ el('equipSearch').value=''; filterEquipment(); };
  document.getElementById('equipSearch').oninput=function(){ filterEquipment(); };
  ['com','baud','parity','addr','databits','stopbits','startref','count','dtype','tcp'].forEach(function(id){ el(id).addEventListener('input',function(){ cmdDirty=false; buildPreview(); }); el(id).addEventListener('change',function(){ cmdDirty=false; buildPreview(); }); });
  el('preview').addEventListener('input', function(){ cmdDirty = true; });
  fetch('?action=ensure_modpoll'); refreshPorts().then(buildPreview); populateEquipment(); buildPreview();
  window.addEventListener('load', sizeEquipListToTerminal);
  window.addEventListener('resize', sizeEquipListToTerminal);

  // Allow wheel scrolling to propagate to page when a panel hits its bounds
  function bindOverscroll(el){
    if(!el){ return; }
    el.addEventListener('wheel', function(e){
      var atTop = el.scrollTop <= 0;
      var atBottom = (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 1);
      if ((e.deltaY < 0 && atTop) || (e.deltaY > 0 && atBottom)) {
        window.scrollBy({ top: e.deltaY, left: 0, behavior: 'auto' });
        e.preventDefault();
      }
    }, { passive: false });
  }
  bindOverscroll(document.querySelector('.equip-list'));
  bindOverscroll(document.querySelector('.log'));
  // Ensure Units scroll stays within panel and not page
  function sizeUnitsScroll(){
    try{
      var scroll = document.querySelector('.units-scroll');
      if(!scroll){ return; }
      scroll.style.maxHeight = 'none';
      scroll.style.overflow = 'visible';
      window.unitsSizeBoost = 1;
    }catch(e){}
  }
  window.addEventListener('load', sizeUnitsScroll);
  window.addEventListener('resize', sizeUnitsScroll);

  // Units table uses page scroll; no special wheel handling needed
  (function(){ })();

  // Global wheel fallback: if no scrollable ancestor can scroll, scroll the page
  function hasScrollableAncestor(node, deltaY){
    var el = node;
    while(el && el !== document.body){
      try{
        var cs = window.getComputedStyle(el);
        var oy = cs.overflowY;
        var can = (oy !== 'visible' && oy !== 'hidden') && el.scrollHeight > el.clientHeight;
        if(can){
          var atTop = el.scrollTop <= 0;
          var atBottom = (el.scrollTop + el.clientHeight) >= (el.scrollHeight - 1);
          if((deltaY < 0 && !atTop) || (deltaY > 0 && !atBottom)) return true;
        }
      }catch(_){ break; }
      el = el.parentElement;
    }
    return false;
  }
  document.addEventListener('wheel', function(e){
    if(!hasScrollableAncestor(e.target, e.deltaY)){
      if(document.documentElement.scrollHeight > window.innerHeight){
        window.scrollBy({ top: e.deltaY, left: 0, behavior: 'auto' });
      }
    }
  }, { passive: true });

</script>
</body>
</html>

