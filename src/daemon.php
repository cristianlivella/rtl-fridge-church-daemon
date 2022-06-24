<?php

require dirname(__FILE__) . '/config.php';
require dirname(__FILE__) . '/vendor/autoload.php';

date_default_timezone_set('Europe/Rome');

$sensorsLastUpdate = [0, 0];
$lastChurchCheck = 0;

$rtl433Pid = null;
$gqrxPid = null;
$ezstreamPid = null;

function logMessage(string $message, int $level = 3) {
    $message = date('Y-m-d H:i:s') . ' | ' . $message . PHP_EOL;
    echo $message;
    telegramMessage($message);
}

function telegramMessage(string $message) {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/sendMessage?chat_id=' . TELEGRAM_CHATID . '&text=' . urlencode($message);
    $ch = curl_init();
    $optArray = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true
    ];
    curl_setopt_array($ch, $optArray);
    curl_exec($ch);
    curl_close($ch);
}

function timeElapsed(int $startTime): int {
    return time() - $startTime;
}

function elaborateFridgeData() {
    global $sensorsLastUpdate, $rtl433Pid;

    if ($rtl433Pid === null) return;

    $content = trim(file_get_contents(dirname(__FILE__) . '/../rtl433log.txt'));

    if (!$content) return;

    $content = explode(PHP_EOL, $content);

    foreach ($content as $message) {
        $message = json_decode($message, true);
        $sensorsLastUpdate[$message['channel']] = strtotime($message['time']);
        logMessage('Received temperature for sensor ' . ($message['channel'] + 1));
    }

    file_put_contents(dirname(__FILE__) . '/../rtl433log.txt', '');
}

function updatedInLastSeconds(int $seconds) {
    global $sensorsLastUpdate;

    foreach ($sensorsLastUpdate as $lastUpdate) {
        if (timeElapsed($lastUpdate) > $seconds) {
            return false;
        }
    }

    return true;
}

function killProcess($pid) {
    if (!$pid) return;

    $pid = intval(trim($pid));
    logMessage('Killing process ' . $pid);
    shell_exec('kill -15 ' . $pid . ' 2>/dev/null');
    sleep(5);
    shell_exec('kill ' . $pid . ' 2>/dev/null');
}

function startRtl433() {
    global $rtl433Pid;

    if ($rtl433Pid) return;

    logMessage('Starting rtl433');
    $command = RTL_433_BINARY . ' -R ' . RTL_433_DEVICE_ID . ' -F json -F "mqtt://' . MQTT_HOST . ':' . MQTT_PORT . ',user=' . MQTT_USERNAME . ',pass=' . MQTT_PASSWORD . ',retain=0,events=' . MQTT_BASE_TOPIC . '[/channel]" > ' . dirname(__FILE__) . '/../rtl433log.txt';
    $rtl433Pid = shell_exec($command . ' 2>/dev/null & echo $!;');
}

function killRtl433() {
    global $rtl433Pid;

    if (!$rtl433Pid) return;

    logMessage('Killing rtl433');
    killProcess($rtl433Pid);

    $rtl433Pid = null;
}

function checkChurchStream() {
    global $lastChurchCheck, $gqrxPid, $ezstreamPid;

    if ($gqrxPid || $ezstreamPid) return;

    logMessage('Checking church stream');

    $rtlPowerInfo = shell_exec('rtl_power -f ' . floor(FREQUENCY) . 'M:' . ceil(FREQUENCY) .  'M:125k -i 4 -1 2>/dev/null');
    $data = array_map('floatval', array_map('trim', explode(',', $rtlPowerInfo)));
    $splicedData = array_splice($data, 6);
    $maxPower = max($splicedData);

    logMessage('Church noise: ' . $maxPower);

    if ($maxPower > NOISE_POWER_THRESHOLD) {
        logMessage('Church stream found');
        killRtl433();
        startStream();
    } else {
        logMessage('Church stream not found');
    }

    logMessage('Church noise: ' . $maxPower);

    $lastChurchCheck = time();
}

function checkChurchStreamEnd() {
    global $lastChurchCheck;

    $streamStatus = shell_exec('curl -s -I -X GET ' . STREAM_URL);
    $streamStatus = explode(PHP_EOL, $streamStatus)[0];
    $streamStatusCode = intval(explode(' ', $streamStatus)[1]);

    if ($streamStatusCode !== 200) {
        logMessage('Steam status code: ' . $streamStatusCode . '. Stopping stream.');
        killStream();
    }

    $lastChurchCheck = time();
}

function startStream() {
    global $gqrxPid, $ezstreamPid;

    if ($gqrxPid || $ezstreamPid) return;

    logMessage('Starting church stream');

    $command = GQRX_PRE_START;
    shell_exec(GQRX_PRE_START . ' > /dev/null 2>&1 & echo $!;');
    sleep(10);
    $command = GQRX_BINARY . ' -c ' . realpath(dirname(__FILE__) . '/../configs/gqrx.conf');
    //$gqrxPid = shell_exec($command . ' > /dev/null 2>&1 & echo $!;');
    $gqrxPid = shell_exec($command . ' > /dev/null & echo $!;');
    logMessage('gqrx pid: ' . $gqrxPid);
    sleep(30);

    $client = Graze\TelnetClient\TelnetClient::factory();
    $client->connect('localhost:7356');
    $client->getSocket()->setOption(SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

    foreach (['U DSP 1', 'F ' . round(FREQUENCY * 1000 * 1000), 'UDP 1 localhost 7355 1'] as $command) {
        try {
            $client->execute($command);
        } catch (Exception $e) {};
    }

    sleep(5);

    $filename = date('Y-m-d-H-i-s') . '.ogg';
    $command = 'nc -l -u localhost 7355 | sox -t raw -r 96000 -b 16 -e signed - -r 96000 -t ogg - | tee ' . dirname(__FILE__) . '/../recordings/' . $filename . ' | ezstream -c ' . dirname(__FILE__) . '/../configs/ezstream.xml';
    $ezstreamPid = shell_exec($command . ' > /dev/null 2>&1 & echo $!;');
    logMessage('ezstream pid: ' . $ezstreamPid);
}

function killStream() {
    global $gqrxPid, $ezstreamPid;

    if (!($gqrxPid || $ezstreamPid)) return;

    logMessage('Killing stream');

    killProcess($gqrxPid);
    killProcess($ezstreamPid);

    $gqrxPid = $ezstreamPid = null;
}

for ($i = 0; $i < 2; $i++) {
    foreach (['rtl_fm', 'rtl_power', 'Gqrx', 'ezstream', 'gqrx', '"nc -l -u localhost 7355"'] as $toKill) {
        shell_exec('pkill ' . ($i === 1 ? '-9 ' : '') .  '-f ' . $toKill . '');
    }
    sleep(5);
}

while (true) {
    elaborateFridgeData();

    if (!updatedInLastSeconds(60 * 60 * 4)) {
        killStream();
        startRtl433();
    } elseif (
        (
            (updatedInLastSeconds(40) && date('H') > 6 && date('H') < 22 && timeElapsed($lastChurchCheck) > 40) ||
            (updatedInLastSeconds(40) && timeElapsed($lastChurchCheck) > 60 * 5) ||
            timeElapsed($lastChurchCheck) > 60 * 6
        ) && !$gqrxPid && !$ezstreamPid
    ) {
        killRtl433();
        checkChurchStream();
    } elseif ($gqrxPid && $ezstreamPid && timeElapsed($lastChurchCheck) > 60) {
        checkChurchStreamEnd();
    } elseif (!$gqrxPid && !$ezstreamPid) {
        startRtl433();
    }

    sleep(5);
}
